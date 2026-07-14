#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
root="$tmp/yoshop-fixture"
shared="$root/shared"
fake_bin="$tmp/bin"
fake_state="$tmp/mysql-state"
mkdir -p "$shared/backups" "$shared/uploads/nested" "$shared/payment" "$fake_bin" "$fake_state"
chmod 0700 "$shared/backups"
printf '[client]\nuser=fixture\npassword=super-secret-fixture\nhost=localhost\n' > "$shared/mysql-client.cnf"
printf 'fixture_shop\n' > "$shared/db-name"
printf 'APP_ENV=production\nPRIVATE_VALUE=fixture-only\n' > "$shared/.env"
printf 'upload one\n' > "$shared/uploads/nested/file one.txt"
printf 'upload two\n' > "$shared/uploads/image.png"
printf '%s\n' 'fixture private key material' > "$shared/payment/apiclient_key.pem"
chmod 0600 "$shared/mysql-client.cnf" "$shared/db-name" "$shared/.env" "$shared/payment/apiclient_key.pem"

cat > "$fake_bin/mysqldump" <<'FAKE_DUMP'
#!/usr/bin/env bash
set -euo pipefail
printf 'mysqldump' >> "$FAKE_MYSQL_LOG"
printf ' %q' "$@" >> "$FAKE_MYSQL_LOG"
printf '\n' >> "$FAKE_MYSQL_LOG"
[[ "${FAKE_DUMP_FAIL:-0}" != 1 ]] || exit 42
cat <<'SQL'
CREATE TABLE `alpha` (`id` int NOT NULL);
INSERT INTO `alpha` VALUES (1);
CREATE TABLE `beta` (`id` int NOT NULL);
SQL
FAKE_DUMP
chmod 0755 "$fake_bin/mysqldump"

cat > "$fake_bin/mysql" <<'FAKE_MYSQL'
#!/usr/bin/env bash
set -euo pipefail
printf 'mysql' >> "$FAKE_MYSQL_LOG"
printf ' %q' "$@" >> "$FAKE_MYSQL_LOG"
printf '\n' >> "$FAKE_MYSQL_LOG"
query=""
database=""
for argument in "$@"; do
  case "$argument" in
    --execute=*) query="${argument#--execute=}" ;;
    --*) ;;
    *) database="$argument" ;;
  esac
done
if [[ "$query" == *'@@GLOBAL.log_bin'* ]]; then
  printf '1\t604800\n'
  exit 0
fi
if [[ "$query" == *'ENGINE NOT IN'* ]]; then
  printf '%s\n' "${FAKE_NON_INNODB:-0}"
  exit 0
fi
if [[ "$query" == *'information_schema.tables'* ]]; then
  if [[ "$database" == fixture_shop || -f "$FAKE_MYSQL_STATE/$database.imported" ]]; then
    printf '2\n'
    exit 0
  fi
  printf '0\n'
  exit 0
fi
if [[ -n "$query" ]]; then
  drop_name="$(printf '%s\n' "$query" | sed -n 's/.*DROP DATABASE IF EXISTS `\([^`]*\)`.*/\1/p')"
  create_name="$(printf '%s\n' "$query" | sed -n 's/.*CREATE DATABASE `\([^`]*\)`.*/\1/p')"
  [[ -z "$drop_name" ]] || rm -f -- "$FAKE_MYSQL_STATE/$drop_name.imported" "$FAKE_MYSQL_STATE/$drop_name.sql"
  [[ -z "$create_name" ]] || : > "$FAKE_MYSQL_STATE/$create_name.created"
  exit 0
fi
[[ -n "$database" ]] || { echo 'fake mysql import missing database' >&2; exit 3; }
cat > "$FAKE_MYSQL_STATE/$database.sql"
: > "$FAKE_MYSQL_STATE/$database.imported"
FAKE_MYSQL
chmod 0755 "$fake_bin/mysql"

export PATH="$fake_bin:$PATH"
export FAKE_MYSQL_LOG="$tmp/mysql.log"
export FAKE_MYSQL_STATE="$fake_state"
export YOSHOP_BACKUP_TEST_MODE=1
export YOSHOP_ROOT="$root"
backup="$repo_root/deploy/scripts/backup-mysql.sh"
restore="$repo_root/deploy/scripts/rollback-mysql.sh"

run_backup_at() {
  local timestamp="$1" type="${2:-daily}"
  YOSHOP_BACKUP_NOW="$timestamp" "$backup" --type "$type"
}

# Eight successful daily fixture runs keep exactly the newest seven.
latest=""
for day in 01 02 03 04 05 06 07 08; do
  latest="$(run_backup_at "202607${day}T021500Z")"
done
[[ -d "$latest" && ! -L "$latest" ]]
[[ ! -e "$shared/backups/daily-20260701T021500Z.complete" ]]
[[ "$(find "$shared/backups" -mindepth 1 -maxdepth 1 -type d -name 'daily-*.complete' | wc -l)" -eq 7 ]]
[[ -z "$(find "$shared/backups" -mindepth 1 -maxdepth 2 -name '*.incomplete' -print -quit)" ]]
[[ "$(stat -c '%a' "$latest")" == 700 ]]
[[ -z "$(find "$latest" -type f -perm /077 -print -quit)" ]]

python3 - "$latest" <<'PY'
import json
import pathlib
import sys
root = pathlib.Path(sys.argv[1])
manifest = json.loads((root / "manifest.json").read_text())
uploads = json.loads((root / "uploads-manifest.json").read_text())
assert manifest["schema"] == 1 and manifest["state"] == "complete"
assert manifest["backup_type"] == "daily" and manifest["retention_days"] == 7
assert manifest["database"]["name"] == "fixture_shop"
assert manifest["database"]["table_count"] == 2
assert manifest["database"]["transactional_tables_only"] is True
assert manifest["mysql_binlog"] == {"enabled": True, "expire_logs_seconds": 604800}
assert manifest["uploads"]["file_count"] == 2
assert uploads["file_count"] == 2
assert {item["path"] for item in uploads["files"]} == {"image.png", "nested/file one.txt"}
assert set(manifest["protected"]["entries"]) == {".env", "mysql-client.cnf", "db-name", "payment"}
PY
(
  cd "$latest"
  sha256sum -c manifest.json.sha256 >/dev/null
  sha256sum -c SHA256SUMS >/dev/null
  gzip -t database.sql.gz
  tar -tzf uploads.tar.gz >/dev/null
  tar -tzf protected.tar.gz >/dev/null
)
if grep -Rqs 'super-secret-fixture\|PRIVATE_VALUE' "$shared/backups"/*/manifest.json "$shared/backups"/*/uploads-manifest.json "$FAKE_MYSQL_LOG"; then
  echo 'secret leaked to manifest or command log' >&2
  exit 1
fi

# A non-transactional table makes --single-transaction inconsistent, so the
# backup fails before creating a dump.
if FAKE_NON_INNODB=1 run_backup_at 20260708T031500Z >"$tmp/engine.out" 2>"$tmp/engine.err"; then
  echo 'expected non-InnoDB rejection' >&2
  exit 1
fi
[[ ! -e "$shared/backups/daily-20260708T031500Z.complete" ]]
[[ ! -e "$shared/backups/daily-20260708T031500Z.incomplete" ]]

# Failed dumps leave neither complete nor incomplete output.
if FAKE_DUMP_FAIL=1 run_backup_at 20260709T021500Z >"$tmp/failed.out" 2>"$tmp/failed.err"; then
  echo 'expected dump failure' >&2
  exit 1
fi
[[ ! -e "$shared/backups/daily-20260709T021500Z.complete" ]]
[[ ! -e "$shared/backups/daily-20260709T021500Z.incomplete" ]]
[[ ! -e "$shared/backups/.backup.lock" ]]
if grep -q 'super-secret-fixture' "$tmp/failed.out" "$tmp/failed.err"; then echo 'secret leaked on failure' >&2; exit 1; fi

# Migration/pre-restore backups use 30-day age retention, not the daily count.
old_migration="$(run_backup_at 20260501T010000Z migration)"
touch -d '31 days ago' "$old_migration"
young_migration="$(run_backup_at 20260710T010000Z migration)"
[[ ! -e "$old_migration" && -d "$young_migration" ]]
python3 - "$young_migration/manifest.json" <<'PY'
import json,sys
payload=json.load(open(sys.argv[1], encoding='utf-8'))
assert payload['backup_type']=='migration' and payload['retention_days']==30
PY

# Legacy flat server-release migration dumps are explicitly 30-day data.
printf 'old migration\n' > "$shared/backups/fixture_shop-20260401-010000.sql.gz"
printf 'checksum\n' > "$shared/backups/fixture_shop-20260401-010000.sql.gz.sha256"
touch -d '31 days ago' "$shared/backups/fixture_shop-20260401-010000.sql.gz" "$shared/backups/fixture_shop-20260401-010000.sql.gz.sha256"
printf 'young migration\n' > "$shared/backups/fixture_shop-20260710-010000.sql.gz"
printf 'unknown\n' > "$shared/backups/do-not-prune.data"
touch -d '90 days ago' "$shared/backups/do-not-prune.data"
latest="$(run_backup_at 20260711T021500Z)"
[[ ! -e "$shared/backups/fixture_shop-20260401-010000.sql.gz" ]]
[[ ! -e "$shared/backups/fixture_shop-20260401-010000.sql.gz.sha256" ]]
[[ -f "$shared/backups/fixture_shop-20260710-010000.sql.gz" ]]
[[ -f "$shared/backups/do-not-prune.data" ]]

# No-argument/default restore selects the latest daily backup, proves it in a
# random temporary DB, and removes that DB afterward.
verify_json="$tmp/verify.json"
"$restore" > "$verify_json"
python3 - "$verify_json" <<'PY'
import json,re,sys
payload=json.load(open(sys.argv[1], encoding='utf-8'))
assert payload['ok'] is True and payload['mode']=='temporary-verification'
assert payload['checksums_verified'] is True and payload['uploads_verified'] is True
assert payload['temporary_database_removed'] is True and payload['table_count']==2
assert re.fullmatch(r'yoshop_verify_[0-9]{8}T[0-9]{6}Z_[0-9a-f]{12}', payload['temporary_database'])
PY
temp_database="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["temporary_database"])' "$verify_json")"
[[ ! -e "$fake_state/$temp_database.imported" ]]

# A wrong token cannot reach production overwrite or create a pre-restore backup.
pre_before="$(find "$shared/backups" -mindepth 1 -maxdepth 1 -type d -name 'pre-restore-*.complete' | wc -l)"
if "$restore" "$latest" --production-overwrite WRONG-TOKEN >"$tmp/wrong.out" 2>"$tmp/wrong.err"; then
  echo 'expected typed-token rejection' >&2
  exit 1
fi
pre_after="$(find "$shared/backups" -mindepth 1 -maxdepth 1 -type d -name 'pre-restore-*.complete' | wc -l)"
[[ "$pre_after" -eq "$pre_before" ]]

# Correct typed token still proves temp restore, then creates a distinct safety backup.
production_json="$tmp/production.json"
YOSHOP_BACKUP_NOW=20260712T030000Z "$restore" "$latest" \
  --production-overwrite OVERWRITE-PRODUCTION-DATABASE > "$production_json"
python3 - "$production_json" <<'PY'
import json,pathlib,sys
payload=json.load(open(sys.argv[1], encoding='utf-8'))
assert payload['ok'] is True and payload['mode']=='production-overwrite'
assert payload['table_count']==2 and payload['checksums_verified'] is True
pre=pathlib.Path(payload['pre_restore_backup'])
assert pre.is_dir() and pre.name.startswith('pre-restore-') and pre.name.endswith('.complete')
manifest=json.loads((pre/'manifest.json').read_text())
assert manifest['backup_type']=='pre-restore' and manifest['retention_days']==30
PY
[[ -f "$fake_state/fixture_shop.imported" ]]
[[ ! -e "$shared/backups/.restore.lock" ]]
[[ -z "$(find "$shared/backups" -maxdepth 1 -name '.restore-*.sql.gz' -print -quit)" ]]

# Tampering is rejected before any additional import.
cp "$latest/database.sql.gz" "$tmp/database.sql.gz.original"
printf 'tamper\n' >> "$latest/database.sql.gz"
imports_before="$(grep -c '^mysql .* fixture_shop$' "$FAKE_MYSQL_LOG" || true)"
if "$restore" "$latest" >"$tmp/tamper.out" 2>"$tmp/tamper.err"; then
  echo 'expected checksum rejection' >&2
  exit 1
fi
imports_after="$(grep -c '^mysql .* fixture_shop$' "$FAKE_MYSQL_LOG" || true)"
[[ "$imports_after" -eq "$imports_before" ]]
mv "$tmp/database.sql.gz.original" "$latest/database.sql.gz"


# Source symlinks are rejected and cannot leave a partial directory.
ln -s image.png "$shared/uploads/link.png"
if run_backup_at 20260713T011500Z >"$tmp/symlink.out" 2>"$tmp/symlink.err"; then
  echo 'expected upload symlink rejection' >&2
  exit 1
fi
rm "$shared/uploads/link.png"
[[ ! -e "$shared/backups/daily-20260713T011500Z.incomplete" ]]

# Defaults and DB-name validation fail closed without exposing their contents.
chmod 0644 "$shared/mysql-client.cnf"
if run_backup_at 20260713T021500Z >"$tmp/mode.out" 2>"$tmp/mode.err"; then
  echo 'expected unsafe defaults mode rejection' >&2
  exit 1
fi
chmod 0600 "$shared/mysql-client.cnf"
printf 'bad-name;DROP\n' > "$shared/db-name"
if run_backup_at 20260714T021500Z >"$tmp/name.out" 2>"$tmp/name.err"; then
  echo 'expected unsafe database name rejection' >&2
  exit 1
fi
printf 'fixture_shop\n' > "$shared/db-name"
chmod 0600 "$shared/db-name"
if grep -q 'super-secret-fixture' "$tmp"/*.out "$tmp"/*.err; then echo 'secret leaked to output' >&2; exit 1; fi

# Fixture mode can never be pointed at the real production root.
if YOSHOP_ROOT=/srv/yoshop YOSHOP_BACKUP_TEST_MODE=1 "$backup" >"$tmp/prod-root.out" 2>"$tmp/prod-root.err"; then
  echo 'expected fixture production-root guard' >&2
  exit 1
fi

grep -q '^User=root$' "$repo_root/deploy/systemd/yoshop-backup.service"
if grep -q '^Documentation=file:/srv/yoshop/current/' "$repo_root/deploy/systemd/yoshop-backup.service"; then
  echo 'backup service points to documentation excluded from releases' >&2
  exit 1
fi
grep -q '^ProtectSystem=strict$' "$repo_root/deploy/systemd/yoshop-backup.service"
grep -q '^ReadWritePaths=/srv/yoshop/shared/backups$' "$repo_root/deploy/systemd/yoshop-backup.service"
grep -q '^RandomizedDelaySec=2h$' "$repo_root/deploy/systemd/yoshop-backup.timer"
grep -q '^Persistent=true$' "$repo_root/deploy/systemd/yoshop-backup.timer"

echo 'PASS production backup atomicity/retention/manifests and guarded restore fixtures'
