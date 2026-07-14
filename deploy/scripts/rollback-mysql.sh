#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 077

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=deploy/scripts/backup-production-common.sh
source "$SCRIPT_DIR/backup-production-common.sh"

usage() {
  cat >&2 <<'USAGE'
Usage:
  rollback-mysql.sh [BACKUP.complete]
  rollback-mysql.sh [BACKUP.complete] --production-overwrite OVERWRITE-PRODUCTION-DATABASE

The default action restores into a random temporary database, verifies the
backup, compares table counts, and removes the temporary database. Production
overwrite additionally requires the exact typed token and automatically creates
a separate pre-restore backup first.
USAGE
}

backup_dir=""
production_token=""
while (($#)); do
  case "$1" in
    --production-overwrite)
      (($# >= 2)) || { usage; exit 2; }
      production_token="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      usage
      exit 2
      ;;
    *)
      [[ -z "$backup_dir" ]] || { usage; exit 2; }
      backup_dir="$1"
      shift
      ;;
  esac
done

backup_init_paths
backup_require_root
for command in mysql gzip sha256sum python3 stat grep chmod date readlink find sort cp mkdir rm; do
  backup_require_cmd "$command"
done
backup_validate_layout
backup_mysql_args

if [[ -z "$backup_dir" ]]; then
  backup_dir="$(find "$YOSHOP_BACKUPS" -mindepth 1 -maxdepth 1 -type d \
    -name 'daily-????????T??????Z.complete' -printf '%p\n' | sort -r | head -n 1)"
  [[ -n "$backup_dir" ]] || backup_fail 'no complete daily backup exists'
fi
[[ -d "$backup_dir" && ! -L "$backup_dir" ]] || backup_fail 'backup must be a complete directory'
backup_dir="$(readlink -f -- "$backup_dir")"
backups_real="$(readlink -f -- "$YOSHOP_BACKUPS")"
[[ "$backup_dir" == "$backups_real"/* ]] || backup_fail 'backup directory must be inside the protected backup root'
backup_basename="$(basename -- "$backup_dir")"
[[ "$backup_basename" =~ ^(daily|migration|pre-restore)-[0-9]{8}T[0-9]{6}Z\.complete$ ]] || \
  backup_fail 'backup directory name is invalid or incomplete'

if [[ "$YOSHOP_TEST_MODE" != 1 ]]; then
  [[ "$(stat -c '%u' -- "$backup_dir")" == 0 ]] || backup_fail 'backup directory must be owned by root'
fi
[[ "$(stat -c '%a' -- "$backup_dir")" == 700 ]] || backup_fail 'backup directory mode must be 0700'
for name in manifest.json manifest.json.sha256 SHA256SUMS database.sql.gz uploads.tar.gz uploads-manifest.json protected.tar.gz; do
  path="$backup_dir/$name"
  [[ -f "$path" && ! -L "$path" ]] || backup_fail "backup artifact is missing or unsafe: $name"
  backup_validate_mode_no_group_or_other "$path"
  [[ "$(stat -c '%a' -- "$path")" == 600 ]] || backup_fail "backup artifact mode must be 0600: $name"
done

validation_output="$(python3 - "$backup_dir" "$backup_basename" "$YOSHOP_DATABASE" <<'PY'
import hashlib
import json
import os
import posixpath
import re
import stat
import sys
import tarfile
from pathlib import Path, PurePosixPath

root = Path(sys.argv[1])
directory_name = sys.argv[2]
expected_database = sys.argv[3]
expected_files = {"database.sql.gz", "uploads.tar.gz", "uploads-manifest.json", "protected.tar.gz"}

manifest_checksum = (root / "manifest.json.sha256").read_text(encoding="utf-8").strip().split()
if len(manifest_checksum) != 2 or manifest_checksum[1] != "manifest.json" or not re.fullmatch(r"[0-9a-f]{64}", manifest_checksum[0]):
    raise SystemExit("invalid manifest checksum file")
manifest_bytes = (root / "manifest.json").read_bytes()
if hashlib.sha256(manifest_bytes).hexdigest() != manifest_checksum[0]:
    raise SystemExit("manifest checksum mismatch")

checksum_rows = {}
for line in (root / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
    match = re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9.-]+)", line)
    if not match or match.group(2) in checksum_rows:
        raise SystemExit("invalid SHA256SUMS")
    checksum_rows[match.group(2)] = match.group(1)
if set(checksum_rows) != expected_files:
    raise SystemExit("SHA256SUMS has unexpected files")
for name, expected in checksum_rows.items():
    digest = hashlib.sha256()
    with (root / name).open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    if digest.hexdigest() != expected:
        raise SystemExit(f"checksum mismatch: {name}")

manifest = json.loads(manifest_bytes)
backup_id = directory_name.removesuffix(".complete")
if manifest.get("schema") != 1 or manifest.get("state") != "complete" or manifest.get("backup_id") != backup_id:
    raise SystemExit("manifest identity/state is invalid")
if manifest.get("backup_type") not in {"daily", "migration", "pre-restore"}:
    raise SystemExit("manifest backup type is invalid")
database = manifest.get("database", {})
if database.get("name") != expected_database:
    raise SystemExit("backup database does not match configured production database")
table_count = database.get("table_count")
if not isinstance(table_count, int) or table_count < 0:
    raise SystemExit("manifest table count is invalid")

uploads_manifest = json.loads((root / "uploads-manifest.json").read_text(encoding="utf-8"))
if uploads_manifest.get("schema") != 1 or not isinstance(uploads_manifest.get("files"), list):
    raise SystemExit("uploads manifest is invalid")
expected_uploads = {}
for item in uploads_manifest["files"]:
    if not isinstance(item, dict) or set(item) != {"path", "sha256", "size"}:
        raise SystemExit("uploads manifest row is invalid")
    path = item["path"]
    if not isinstance(path, str) or not path or path.startswith("/") or ".." in PurePosixPath(path).parts:
        raise SystemExit("uploads manifest path is unsafe")
    if path in expected_uploads or not isinstance(item["size"], int) or item["size"] < 0 or not re.fullmatch(r"[0-9a-f]{64}", item["sha256"]):
        raise SystemExit("uploads manifest metadata is invalid")
    expected_uploads[path] = (item["size"], item["sha256"])
if uploads_manifest.get("file_count") != len(expected_uploads) or uploads_manifest.get("total_bytes") != sum(value[0] for value in expected_uploads.values()):
    raise SystemExit("uploads manifest totals are invalid")
if manifest.get("uploads", {}).get("file_count") != len(expected_uploads) or manifest.get("uploads", {}).get("total_bytes") != uploads_manifest["total_bytes"]:
    raise SystemExit("backup/uploads manifest totals differ")

def safe_member(member, allowed_roots):
    raw_parts = PurePosixPath(member.name).parts
    name = posixpath.normpath(member.name)
    parts = PurePosixPath(name).parts
    if member.name.startswith("/") or name in {"", ".", ".."} or ".." in raw_parts or ".." in parts:
        raise SystemExit("archive contains an unsafe path")
    if parts[0] not in allowed_roots:
        raise SystemExit("archive contains an unexpected top-level path")
    if member.issym() or member.islnk() or not (member.isdir() or member.isfile()):
        raise SystemExit("archive contains a link or special file")
    return name

actual_uploads = {}
with tarfile.open(root / "uploads.tar.gz", "r:gz") as archive:
    for member in archive:
        name = safe_member(member, {"uploads"})
        if not member.isfile():
            continue
        relative = PurePosixPath(name).relative_to("uploads").as_posix()
        handle = archive.extractfile(member)
        if handle is None:
            raise SystemExit("cannot read upload archive member")
        digest = hashlib.sha256()
        size = 0
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
            size += len(chunk)
        if relative in actual_uploads:
            raise SystemExit("duplicate upload archive member")
        actual_uploads[relative] = (size, digest.hexdigest())
if actual_uploads != expected_uploads:
    raise SystemExit("uploads archive does not match uploads manifest")

seen_protected = set()
with tarfile.open(root / "protected.tar.gz", "r:gz") as archive:
    for member in archive:
        name = safe_member(member, {".env", "mysql-client.cnf", "db-name", "payment"})
        seen_protected.add(PurePosixPath(name).parts[0])
if seen_protected != {".env", "mysql-client.cnf", "db-name", "payment"}:
    raise SystemExit("protected archive is incomplete")

print(table_count)
print(checksum_rows["database.sql.gz"])
PY
)" || backup_fail 'backup manifest/archive validation failed'
mapfile -t validation_fields <<< "$validation_output"
((${#validation_fields[@]} == 2)) || backup_fail 'backup validation returned invalid metadata'
expected_table_count="${validation_fields[0]}"
expected_dump_sha256="${validation_fields[1]}"
[[ "$expected_table_count" =~ ^[0-9]+$ && "$expected_dump_sha256" =~ ^[0-9a-f]{64}$ ]] || \
  backup_fail 'backup validation returned unsafe metadata'
gzip -t "$backup_dir/database.sql.gz"

if [[ -n "$production_token" && "$production_token" != OVERWRITE-PRODUCTION-DATABASE ]]; then
  backup_fail 'production overwrite token is invalid'
fi

restore_dump="$backup_dir/database.sql.gz"
restore_copy=""
restore_lock=""
restore_lock_acquired=0
temporary_database=""
cleanup_restore() {
  local status=$?
  if [[ -n "$temporary_database" ]]; then
    mysql "${YOSHOP_MYSQL_ARGS[@]}" --execute="DROP DATABASE IF EXISTS \`$temporary_database\`" >/dev/null 2>&1 || true
    temporary_database=""
  fi
  [[ -z "$restore_copy" ]] || rm -f -- "$restore_copy"
  if [[ $restore_lock_acquired -eq 1 ]]; then
    rmdir "$restore_lock" 2>/dev/null || true
  fi
  exit "$status"
}
trap cleanup_restore EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

if [[ -n "$production_token" ]]; then
  restore_lock="$YOSHOP_BACKUPS/.restore.lock"
  mkdir "$restore_lock" 2>/dev/null || backup_fail 'another production restore is already running'
  restore_lock_acquired=1
  restore_copy="$YOSHOP_BACKUPS/.restore-$PPID-$$.sql.gz"
  cp -- "$restore_dump" "$restore_copy"
  chmod 0600 "$restore_copy"
  copied_sha256="$(sha256sum "$restore_copy")"
  copied_sha256="${copied_sha256%% *}"
  [[ "$copied_sha256" == "$expected_dump_sha256" ]] || backup_fail 'protected restore copy checksum mismatch'
  gzip -t "$restore_copy"
  restore_dump="$restore_copy"
fi

drop_temporary_database() {
  if [[ -n "$temporary_database" ]]; then
    mysql "${YOSHOP_MYSQL_ARGS[@]}" --execute="DROP DATABASE IF EXISTS \`$temporary_database\`" >/dev/null 2>&1 || true
    temporary_database=""
  fi
}

make_temporary_database() {
  local suffix
  suffix="$(python3 - <<'PY'
import secrets
print(secrets.token_hex(6))
PY
)"
  temporary_database="yoshop_verify_$(date -u +%Y%m%dT%H%M%SZ)_$suffix"
  [[ "$temporary_database" =~ ^[A-Za-z0-9_]{1,64}$ ]] || backup_fail 'failed to generate safe temporary database name'
}

restore_database() {
  local target="$1" actual
  mysql "${YOSHOP_MYSQL_ARGS[@]}" --execute="DROP DATABASE IF EXISTS \`$target\`; CREATE DATABASE \`$target\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
  gzip -dc "$restore_dump" | mysql "${YOSHOP_MYSQL_ARGS[@]}" "$target"
  actual="$(mysql "${YOSHOP_MYSQL_ARGS[@]}" "$target" \
    --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")"
  [[ "$actual" =~ ^[0-9]+$ ]] || backup_fail 'restored database returned an invalid table count'
  [[ "$actual" == "$expected_table_count" ]] || backup_fail 'restored database table count does not match manifest'
  RESTORED_TABLE_COUNT="$actual"
}

# Every path, including production overwrite, proves the dump in an isolated DB first.
make_temporary_database
verified_temporary_database="$temporary_database"
restore_database "$temporary_database"
drop_temporary_database

if [[ -z "$production_token" ]]; then
  python3 - "$backup_basename" "$verified_temporary_database" "$RESTORED_TABLE_COUNT" <<'PY'
import json
import sys
print(json.dumps({
    "schema": 1,
    "ok": True,
    "mode": "temporary-verification",
    "backup": sys.argv[1],
    "temporary_database": sys.argv[2],
    "temporary_database_removed": True,
    "table_count": int(sys.argv[3]),
    "checksums_verified": True,
    "uploads_verified": True,
}, separators=(",", ":"), sort_keys=True))
PY
  exit 0
fi

backup_script="$SCRIPT_DIR/backup-mysql.sh"
[[ -x "$backup_script" ]] || backup_fail 'pre-restore backup entry point is unavailable'
pre_restore_backup="$($backup_script --type pre-restore)"
[[ -d "$pre_restore_backup" && ! -L "$pre_restore_backup" ]] || backup_fail 'separate pre-restore backup did not complete'

restore_database "$YOSHOP_DATABASE"
python3 - "$backup_basename" "$pre_restore_backup" "$RESTORED_TABLE_COUNT" <<'PY'
import json
import sys
print(json.dumps({
    "schema": 1,
    "ok": True,
    "mode": "production-overwrite",
    "backup": sys.argv[1],
    "pre_restore_backup": sys.argv[2],
    "table_count": int(sys.argv[3]),
    "checksums_verified": True,
    "uploads_verified": True,
}, separators=(",", ":"), sort_keys=True))
PY
