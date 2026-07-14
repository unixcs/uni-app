#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 077

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=deploy/scripts/backup-production-common.sh
source "$SCRIPT_DIR/backup-production-common.sh"

usage() {
  cat >&2 <<'USAGE'
Usage: backup-mysql.sh [--type daily|migration|pre-restore]

Creates one complete production backup under /srv/yoshop/shared/backups.
YOSHOP_ROOT and YOSHOP_BACKUP_NOW are fixture-only overrides and require
YOSHOP_BACKUP_TEST_MODE=1.
USAGE
}

backup_type=daily
while (($#)); do
  case "$1" in
    --type)
      (($# >= 2)) || { usage; exit 2; }
      backup_type="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      usage
      exit 2
      ;;
  esac
done
[[ "$backup_type" =~ ^(daily|migration|pre-restore)$ ]] || backup_fail 'invalid backup type'

backup_init_paths
backup_require_root
for command in mysql mysqldump gzip tar sha256sum python3 stat grep chmod date mv find sort cmp rm mkdir; do
  backup_require_cmd "$command"
done
backup_validate_layout
backup_mysql_args

for source_path in "$YOSHOP_SHARED/.env" "$YOSHOP_SHARED/uploads" "$YOSHOP_SHARED/payment"; do
  [[ -e "$source_path" && ! -L "$source_path" ]] || backup_fail "required backup source is missing or unsafe: $source_path"
done
[[ -f "$YOSHOP_SHARED/.env" ]] || backup_fail 'shared .env must be a regular file'
[[ -d "$YOSHOP_SHARED/uploads" && -d "$YOSHOP_SHARED/payment" ]] || backup_fail 'uploads/payment must be directories'

now="${YOSHOP_BACKUP_NOW:-$(date -u +%Y%m%dT%H%M%SZ)}"
if [[ "$YOSHOP_TEST_MODE" != 1 && -n "${YOSHOP_BACKUP_NOW:-}" ]]; then
  backup_fail 'YOSHOP_BACKUP_NOW is allowed only in test mode'
fi
[[ "$now" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || backup_fail 'invalid backup timestamp'
backup_id="$backup_type-$now"
incomplete="$YOSHOP_BACKUPS/$backup_id.incomplete"
complete="$YOSHOP_BACKUPS/$backup_id.complete"
lock_dir="$YOSHOP_BACKUPS/.backup.lock"
[[ ! -e "$incomplete" && ! -L "$incomplete" && ! -e "$complete" && ! -L "$complete" ]] || \
  backup_fail "backup ID already exists: $backup_id"
finished=0
lock_acquired=0
cleanup() {
  local status=$?
  if [[ $finished -ne 1 && -d "$incomplete" && ! -L "$incomplete" ]]; then
    rm -rf -- "$incomplete"
  fi
  if [[ $lock_acquired -eq 1 ]]; then
    rmdir "$lock_dir" 2>/dev/null || true
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
mkdir "$lock_dir" 2>/dev/null || backup_fail 'another backup is already running'
lock_acquired=1
mkdir -m 0700 "$incomplete"

inventory_tree() {
  local root="$1" output="$2" public_paths="$3"
  python3 - "$root" "$output" "$public_paths" <<'PY'
import hashlib
import json
import os
import stat
import sys
from pathlib import Path

root = Path(sys.argv[1])
output = Path(sys.argv[2])
public_paths = sys.argv[3] == "1"
if not root.is_dir() or root.is_symlink():
    raise SystemExit("unsafe inventory root")
files = []
total = 0
for base, dirs, names in os.walk(root, followlinks=False):
    base_path = Path(base)
    for name in sorted(dirs):
        path = base_path / name
        if path.is_symlink():
            raise SystemExit("symlinks are not allowed in backup sources")
    for name in sorted(names):
        path = base_path / name
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode):
            raise SystemExit("special files are not allowed in backup sources")
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        item = {"size": info.st_size, "sha256": digest.hexdigest()}
        if public_paths:
            item["path"] = path.relative_to(root).as_posix()
        files.append(item)
        total += info.st_size
files.sort(key=lambda item: item.get("path", ""))
payload = {"schema": 1, "file_count": len(files), "total_bytes": total, "files": files}
output.write_text(json.dumps(payload, separators=(",", ":"), sort_keys=True) + "\n", encoding="utf-8")
os.chmod(output, 0o600)
PY
}

protected_inventory() {
  local output="$1"
  python3 - "$YOSHOP_SHARED" "$output" <<'PY'
import hashlib
import json
import os
import stat
import sys
from pathlib import Path

shared = Path(sys.argv[1])
output = Path(sys.argv[2])
roots = [shared / ".env", shared / "mysql-client.cnf", shared / "db-name", shared / "payment"]
items = []
for root in roots:
    if root.is_symlink() or not root.exists():
        raise SystemExit("unsafe protected backup source")
    if root.is_file():
        paths = [root]
    else:
        paths = []
        for base, dirs, names in os.walk(root, followlinks=False):
            base_path = Path(base)
            for name in sorted(dirs):
                if (base_path / name).is_symlink():
                    raise SystemExit("symlinks are not allowed in protected backup sources")
            paths.extend(base_path / name for name in sorted(names))
    for path in paths:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode):
            raise SystemExit("special files are not allowed in protected backup sources")
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        items.append({"relative": path.relative_to(shared).as_posix(), "size": info.st_size, "sha256": digest.hexdigest()})
items.sort(key=lambda item: item["relative"])
output.write_text(json.dumps(items, separators=(",", ":"), sort_keys=True) + "\n", encoding="utf-8")
os.chmod(output, 0o600)
PY
}

uploads_before="$incomplete/.uploads-before.json"
uploads_after="$incomplete/.uploads-after.json"
protected_before="$incomplete/.protected-before.json"
protected_after="$incomplete/.protected-after.json"
inventory_tree "$YOSHOP_SHARED/uploads" "$uploads_before" 1
protected_inventory "$protected_before"

count_query="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
non_transactional_query="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND ENGINE NOT IN ('InnoDB')"
table_count_before="$(mysql "${YOSHOP_MYSQL_ARGS[@]}" "$YOSHOP_DATABASE" --execute="$count_query")"
[[ "$table_count_before" =~ ^[0-9]+$ ]] || backup_fail 'MySQL returned an invalid table count'
non_transactional_count="$(mysql "${YOSHOP_MYSQL_ARGS[@]}" "$YOSHOP_DATABASE" --execute="$non_transactional_query")"
[[ "$non_transactional_count" =~ ^[0-9]+$ ]] || backup_fail 'MySQL returned an invalid storage-engine count'
[[ "$non_transactional_count" == 0 ]] || backup_fail 'non-InnoDB tables prevent a consistent single-transaction backup'

binlog_row="$(mysql "${YOSHOP_MYSQL_ARGS[@]}" --execute='SELECT @@GLOBAL.log_bin, @@GLOBAL.binlog_expire_logs_seconds')"
IFS=$'\t' read -r binlog_enabled binlog_expire_seconds extra <<< "$binlog_row"
[[ -z "${extra:-}" && "$binlog_enabled" =~ ^[01]$ && "$binlog_expire_seconds" =~ ^[0-9]+$ ]] || \
  backup_fail 'MySQL returned invalid binlog retention data'
if [[ "$YOSHOP_TEST_MODE" != 1 ]]; then
  [[ "$binlog_enabled" == 1 ]] || backup_fail 'MySQL binary logging is not enabled'
  ((binlog_expire_seconds >= 604800)) || backup_fail 'MySQL binlog retention is shorter than 7 days'
fi

mysqldump \
  "--defaults-extra-file=$YOSHOP_MYSQL_CNF" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF \
  --no-tablespaces \
  "$YOSHOP_DATABASE" | gzip -9 > "$incomplete/database.sql.gz"
gzip -t "$incomplete/database.sql.gz"

table_count_after="$(mysql "${YOSHOP_MYSQL_ARGS[@]}" "$YOSHOP_DATABASE" --execute="$count_query")"
[[ "$table_count_after" == "$table_count_before" ]] || backup_fail 'database schema changed during backup'

tar --hard-dereference -C "$YOSHOP_SHARED" -czf "$incomplete/uploads.tar.gz" -- uploads
tar --hard-dereference -C "$YOSHOP_SHARED" -czf "$incomplete/protected.tar.gz" -- .env mysql-client.cnf db-name payment
inventory_tree "$YOSHOP_SHARED/uploads" "$uploads_after" 1
protected_inventory "$protected_after"
cmp -s "$uploads_before" "$uploads_after" || backup_fail 'uploads changed during backup'
cmp -s "$protected_before" "$protected_after" || backup_fail 'protected files changed during backup'
mv "$uploads_after" "$incomplete/uploads-manifest.json"
rm -f -- "$uploads_before" "$protected_before" "$protected_after"

(
  cd "$incomplete"
  sha256sum database.sql.gz uploads.tar.gz uploads-manifest.json protected.tar.gz > SHA256SUMS
)

retention_days=7
[[ "$backup_type" == daily ]] || retention_days=30
created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
python3 - "$incomplete" "$backup_id" "$backup_type" "$created_at" "$YOSHOP_DATABASE" \
  "$table_count_before" "$binlog_enabled" "$binlog_expire_seconds" "$retention_days" <<'PY'
import hashlib
import json
import os
import sys
from pathlib import Path

(root_text, backup_id, backup_type, created_at, database, table_count,
 binlog_enabled, binlog_expire_seconds, retention_days) = sys.argv[1:]
root = Path(root_text)
uploads = json.loads((root / "uploads-manifest.json").read_text(encoding="utf-8"))

def metadata(name):
    path = root / name
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return {"file": name, "bytes": path.stat().st_size, "sha256": digest.hexdigest()}

payload = {
    "schema": 1,
    "state": "complete",
    "backup_id": backup_id,
    "backup_type": backup_type,
    "created_at": created_at,
    "retention_days": int(retention_days),
    "database": {
        "name": database,
        "table_count": int(table_count),
        "transactional_tables_only": True,
        "dump": metadata("database.sql.gz"),
    },
    "uploads": {
        "archive": metadata("uploads.tar.gz"),
        "manifest": metadata("uploads-manifest.json"),
        "file_count": uploads["file_count"],
        "total_bytes": uploads["total_bytes"],
    },
    "protected": {
        "archive": metadata("protected.tar.gz"),
        "entries": [".env", "mysql-client.cnf", "db-name", "payment"],
    },
    "mysql_binlog": {
        "enabled": binlog_enabled == "1",
        "expire_logs_seconds": int(binlog_expire_seconds),
    },
    "checksums_file": "SHA256SUMS",
}
path = root / "manifest.json"
path.write_text(json.dumps(payload, separators=(",", ":"), sort_keys=True) + "\n", encoding="utf-8")
os.chmod(path, 0o600)
PY
(
  cd "$incomplete"
  sha256sum manifest.json > manifest.json.sha256
)
find "$incomplete" -type d -exec chmod 0700 {} +
find "$incomplete" -type f -exec chmod 0600 {} +
mv "$incomplete" "$complete"
finished=1

# Keep exactly the newest seven atomic daily backups. Migration and pre-restore
# safety points are age-based and are never handled by the daily count policy.
mapfile -t daily_backups < <(find "$YOSHOP_BACKUPS" -mindepth 1 -maxdepth 1 -type d \
  -name 'daily-????????T??????Z.complete' -printf '%f\n' | sort -r)
for ((index=7; index<${#daily_backups[@]}; index++)); do
  candidate="${daily_backups[$index]}"
  [[ "$candidate" =~ ^daily-[0-9]{8}T[0-9]{6}Z\.complete$ ]] || continue
  rm -rf -- "$YOSHOP_BACKUPS/${candidate:?}"
done

while IFS= read -r -d '' path; do
  candidate="$(basename -- "$path")"
  [[ "$candidate" =~ ^(migration|pre-restore)-[0-9]{8}T[0-9]{6}Z\.complete$ ]] || continue
  rm -rf -- "$path"
done < <(find "$YOSHOP_BACKUPS" -mindepth 1 -maxdepth 1 -type d \
  \( -name 'migration-????????T??????Z.complete' -o -name 'pre-restore-????????T??????Z.complete' \) \
  -mtime +29 -print0)

# server-release.sh historically creates these flat files immediately before
# migrations. They are migration safety points, so daily pruning never touches
# them and this explicit compatibility rule gives them 30-day retention.
while IFS= read -r -d '' path; do
  candidate="$(basename -- "$path")"
  [[ "$candidate" =~ ^${YOSHOP_DATABASE}-[0-9]{8}-[0-9]{6}\.sql\.gz$ ]] || continue
  rm -f -- "$path" "$path.sha256"
done < <(find "$YOSHOP_BACKUPS" -mindepth 1 -maxdepth 1 -type f \
  -name "$YOSHOP_DATABASE-????????-??????.sql.gz" -mtime +29 -print0)

while IFS= read -r -d '' path; do
  candidate="$(basename -- "$path")"
  [[ "$candidate" =~ ^(daily|migration|pre-restore)-[0-9]{8}T[0-9]{6}Z\.incomplete$ ]] || continue
  rm -rf -- "$path"
done < <(find "$YOSHOP_BACKUPS" -mindepth 1 -maxdepth 1 -type d \
  -name '*.incomplete' -mtime +0 -print0)

printf '%s\n' "$complete"
