#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "$script_dir/../.." && pwd -P)"
cd "$repo_root"

bash -n scripts/repair-local-runtime.sh scripts/cleanup-local.sh
# The dollar expression is intentionally matched as literal script source.
# shellcheck disable=SC2016
grep -Fq '"$script_dir/repair-local-runtime.sh"' scripts/cleanup-local.sh
grep -Fq 'User=www-data' scripts/systemd/yoshop2.0-timer-local-override.conf
grep -Fq 'Group=www-data' scripts/systemd/yoshop2.0-timer-local-override.conf

# The old documented command deleted runtime contents without restoring the
# PHP-FPM ownership boundary. Keep it out of tracked shell/Markdown files.
dangerous='rm -rf /opt/yoshop/yoshop2.0/runtime/'\*
if git grep -n -F "$dangerous" -- '*.md' '*.sh'; then
  echo 'Unsafe raw runtime deletion command is documented again.' >&2
  exit 1
fi

tmp="$(mktemp -d)"
chmod 0755 "$tmp"
trap 'rm -rf -- "$tmp"' EXIT

# Production releases use a runtime symlink to shared data. The local helper
# must refuse that layout instead of changing production permissions.
mkdir -p "$tmp/symlink-repo/.git" "$tmp/symlink-repo/scripts" \
  "$tmp/symlink-repo/yoshop2.0" "$tmp/shared-runtime"
touch "$tmp/symlink-repo/yoshop2.0/think"
cp scripts/repair-local-runtime.sh "$tmp/symlink-repo/scripts/"
ln -s "$tmp/shared-runtime" "$tmp/symlink-repo/yoshop2.0/runtime"
if "$tmp/symlink-repo/scripts/repair-local-runtime.sh" >"$tmp/refusal.log" 2>&1; then
  echo 'Expected production-style runtime symlink refusal.' >&2
  exit 1
fi
grep -Fq 'production-style layout' "$tmp/refusal.log"

# Root is required for the ownership integration check. Non-root CI still runs
# syntax, documentation, override, and production-refusal contracts above.
if (( EUID == 0 )); then
  mkdir -p "$tmp/cleanup-repo/.git" "$tmp/cleanup-repo/scripts" \
    "$tmp/cleanup-repo/yoshop2.0/runtime/cache" \
    "$tmp/cleanup-repo/yoshop2.0-uniapp"
  touch "$tmp/cleanup-repo/yoshop2.0/think"
  printf '{}\n' > "$tmp/cleanup-repo/yoshop2.0-uniapp/package.json"
  printf stale > "$tmp/cleanup-repo/yoshop2.0/runtime/cache/stale.php"
  cp scripts/cleanup-local.sh scripts/repair-local-runtime.sh "$tmp/cleanup-repo/scripts/"

  "$tmp/cleanup-repo/scripts/cleanup-local.sh" --apply >"$tmp/cleanup.log"
  test "$(stat -c '%U:%G' "$tmp/cleanup-repo/yoshop2.0/runtime")" = 'www-data:www-data'
  printf ok | runuser -u www-data -- tee \
    "$tmp/cleanup-repo/yoshop2.0/runtime/cache/06/contract.php" >/dev/null
  grep -Fq 'PHP-FPM write test passed' "$tmp/cleanup.log"
fi

echo 'local runtime contract: PASS'
