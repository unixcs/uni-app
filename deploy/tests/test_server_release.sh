#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
root="$tmp/server"
mkdir -p "$root/incoming"

make_release() {
  local server_root="$1" id="$2" marker="$3"
  local stage="$tmp/stage-$id" package="$server_root/incoming/yoshop-$id.tar.gz"
  rm -rf "$stage"
  mkdir -p "$stage/yoshop2.0/public/admin" "$stage/yoshop2.0/public/store" \
    "$stage/yoshop2.0/vendor" "$stage/yoshop2.0/data" "$server_root/incoming"
  printf '#!/usr/bin/env php\n' >"$stage/yoshop2.0/think"
  printf '<?php echo "ok";\n' >"$stage/yoshop2.0/public/index.php"
  printf '%s\n' "$marker" >"$stage/yoshop2.0/public/index.html"
  printf 'admin\n' >"$stage/yoshop2.0/public/admin/index.html"
  printf 'store\n' >"$stage/yoshop2.0/public/store/index.html"
  printf '<?php\n' >"$stage/yoshop2.0/vendor/autoload.php"
  for n in $(seq 1 110); do printf '%s\n' "$n" >"$stage/yoshop2.0/file-$n.txt"; done
  python3 - "$stage" "$id" <<'PYMANIFEST'
import hashlib,json,sys
from pathlib import Path
root=Path(sys.argv[1])
files=[]
for path in sorted(root.rglob('*')):
    if path.is_file() and not path.is_symlink():
        files.append({'path':path.relative_to(root).as_posix(),'size':path.stat().st_size,
                      'sha256':hashlib.sha256(path.read_bytes()).hexdigest()})
(root/'release-manifest.json').write_text(json.dumps({
    'schema':1,'release_id':sys.argv[2],'git_commit':'test','file_count':len(files),'files':files
})+'\n')
PYMANIFEST
  tar -czf "$package" -C "$stage" .
  sha256sum "$package" | awk '{print $1}'
}

make_unsafe_release() {
  local server_root="$1" id="$2" package
  package="$server_root/incoming/yoshop-$id.tar.gz"
  python3 - "$package" <<'PY'
import io,tarfile,sys
with tarfile.open(sys.argv[1], 'w:gz') as archive:
    member=tarfile.TarInfo('escape-link')
    member.type=tarfile.SYMTYPE
    member.linkname='../outside'
    archive.addfile(member)
PY
  sha256sum "$package" | awk '{print $1}'
}

run_release_at() {
  local server_root="$1"
  shift
  YOSHOP_TEST_MODE=1 YOSHOP_ROOT="$server_root" \
    "$repo_root/deploy/scripts/server-release.sh" "$@"
}

run_release() {
  run_release_at "$root" "$@"
}

assert_status() {
  local file="$1" expected_current="$2" expected_prepared="$3" expected_ok="$4"
  python3 - "$file" "$expected_current" "$expected_prepared" "$expected_ok" <<'PY'
import json,sys
payload=json.load(open(sys.argv[1], encoding='utf-8'))
expected_current=None if sys.argv[2]=='-' else sys.argv[2]
expected_prepared=None if sys.argv[3]=='-' else sys.argv[3]
assert payload['current']==expected_current, payload
if expected_prepared is None:
    assert payload['prepared'] is None, payload
else:
    assert payload['prepared']['release_id']==expected_prepared, payload
    assert payload['prepared']['state']=='prepared', payload
    assert payload['prepared']['verified'] is True, payload
assert payload['ok'] is (sys.argv[4]=='true'), payload
PY
}

id1=20260715000001-111111111111
sha1="$(make_release "$root" "$id1" one)"
run_release prepare "$id1" "yoshop-$id1.tar.gz" "$sha1" >"$tmp/prepare-1.json"
assert_status "$tmp/prepare-1.json" - "$id1" true
[[ ! -e "$root/current" ]]
[[ -d "$root/releases/$id1" && ! -e "$root/incoming/yoshop-$id1.tar.gz" ]]
[[ -L "$root/releases/$id1/yoshop2.0/.env" ]]
[[ "$(readlink "$root/releases/$id1/yoshop2.0/public/uploads")" == "$root/shared/uploads" ]]
[[ -z "$(find "$root/releases/$id1" -xdev \( -type f -o -type d \) -perm /222 -print -quit)" ]]

# Duplicate prepare and activation of an ID other than the recorded candidate are closed.
if run_release prepare "$id1" "yoshop-$id1.tar.gz" "$sha1" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected duplicate prepared-state failure' >&2; exit 1
fi
wrong=20260715000009-999999999999
if run_release activate "$wrong" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected prepared release-id mismatch' >&2; exit 1
fi
run_release status >"$tmp/status-prepared.json"
assert_status "$tmp/status-prepared.json" - "$id1" true

run_release activate "$id1" >"$tmp/activate-1.json"
assert_status "$tmp/activate-1.json" "$id1" - true
[[ "$(basename "$(readlink -f "$root/current")")" == "$id1" ]]

# The routine install command remains prepare + activate + health in one invocation.
id2=20260715000002-222222222222
sha2="$(make_release "$root" "$id2" two)"
run_release install "$id2" "yoshop-$id2.tar.gz" "$sha2" >"$tmp/install-2.json"
assert_status "$tmp/install-2.json" "$id2" - true
run_release rollback >"$tmp/rollback.json"
assert_status "$tmp/rollback.json" "$id1" - true

# Failed health restores the old current and leaves the immutable candidate retryable.
id3=20260715000003-333333333333
sha3="$(make_release "$root" "$id3" broken)"
run_release prepare "$id3" "yoshop-$id3.tar.gz" "$sha3" >"$tmp/prepare-3.json"
if YOSHOP_TEST_MODE=1 YOSHOP_TEST_HEALTH_FAIL_RELEASE="$id3" YOSHOP_ROOT="$root" \
  "$repo_root/deploy/scripts/server-release.sh" activate "$id3" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected simulated health failure' >&2; exit 1
fi
[[ "$(basename "$(readlink -f "$root/current")")" == "$id1" ]]
run_release status >"$tmp/status-after-failure.json"
assert_status "$tmp/status-after-failure.json" "$id1" "$id3" true
run_release activate "$id3" >"$tmp/activate-retry.json"
assert_status "$tmp/activate-retry.json" "$id3" - true

# Existing release IDs and unsafe archive member types are rejected without changing current.
sha3_again="$(make_release "$root" "$id3" duplicate)"
if run_release prepare "$id3" "yoshop-$id3.tar.gz" "$sha3_again" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected duplicate release-directory failure' >&2; exit 1
fi
unsafe=20260715000004-444444444444
unsafe_sha="$(make_unsafe_release "$root" "$unsafe")"
if run_release prepare "$unsafe" "yoshop-$unsafe.tar.gz" "$unsafe_sha" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected unsafe archive failure' >&2; exit 1
fi
[[ ! -e "$tmp/outside" && ! -e "$root/releases/$unsafe" ]]
[[ "$(basename "$(readlink -f "$root/current")")" == "$id3" ]]

# A malformed prepared state is visible as JSON and cannot be activated.
id5=20260715000005-555555555555
sha5="$(make_release "$root" "$id5" five)"
run_release prepare "$id5" "yoshop-$id5.tar.gz" "$sha5" >"$tmp/prepare-5.json"
printf 'tampered\n' >"$root/releases/$id5/yoshop2.0/file-1.txt"
if run_release activate "$id5" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected changed candidate failure' >&2; exit 1
fi
run_release status >"$tmp/tampered-status.json"
python3 - "$tmp/tampered-status.json" <<'PY'
import json,sys
payload=json.load(open(sys.argv[1], encoding='utf-8'))
assert payload['ok'] is False, payload
assert payload['prepared']['state']=='invalid', payload
assert 'candidate file changed' in payload['prepared']['error'], payload
PY
printf '1\n' >"$root/releases/$id5/yoshop2.0/file-1.txt"
run_release status >"$tmp/restored-candidate-status.json"
assert_status "$tmp/restored-candidate-status.json" "$id3" "$id5" true
printf '{not-json\n' >"$root/state/prepared.json"
if run_release activate "$id5" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected malformed prepared-state failure' >&2; exit 1
fi
run_release status >"$tmp/invalid-status.json"
python3 - "$tmp/invalid-status.json" "$id3" <<'PY'
import json,sys
payload=json.load(open(sys.argv[1], encoding='utf-8'))
assert payload['ok'] is False, payload
assert payload['current']==sys.argv[2], payload
assert payload['prepared']['state']=='invalid', payload
assert payload['prepared']['verified'] is False, payload
PY

# First activation has no rollback target: failed health removes current and remains closed.
first_root="$tmp/first-server"
mkdir -p "$first_root/incoming"
first_id=20260715000101-aaaaaaaaaaaa
first_sha="$(make_release "$first_root" "$first_id" first)"
run_release_at "$first_root" prepare "$first_id" "yoshop-$first_id.tar.gz" "$first_sha" >"$tmp/first-prepare.json"
if YOSHOP_TEST_MODE=1 YOSHOP_TEST_HEALTH_FAIL_RELEASE="$first_id" YOSHOP_ROOT="$first_root" \
  "$repo_root/deploy/scripts/server-release.sh" activate "$first_id" >"$tmp/unexpected.json" 2>/dev/null; then
  echo 'expected first-activation health failure' >&2; exit 1
fi
[[ ! -e "$first_root/current" && ! -L "$first_root/current" ]]
run_release_at "$first_root" status >"$tmp/first-failed-status.json"
assert_status "$tmp/first-failed-status.json" - "$first_id" true

# Every successful command above emitted exactly one machine-readable JSON document on stdout.
for output in "$tmp"/*.json; do
  [[ "$(basename "$output")" == unexpected.json ]] && continue
  python3 -m json.tool "$output" >/dev/null
done

echo 'PASS server release prepare/activate/install/rollback/state/health integration'
