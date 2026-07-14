#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'
umask 027

ROOT="${YOSHOP_ROOT:-/srv/yoshop}"
INCOMING="$ROOT/incoming"
RELEASES="$ROOT/releases"
SHARED="$ROOT/shared"
CURRENT="$ROOT/current"
STATE="$ROOT/state"
PREPARED_STATE="$STATE/prepared.json"
LOCK="$ROOT/release.lock"
APP_SUBDIR="yoshop2.0"
DOMAIN="${YOSHOP_DOMAIN:-wx.gxwqb.cn}"
HEALTH_URL="${YOSHOP_HEALTH_URL:-https://wx.gxwqb.cn/}"
RETAIN="${YOSHOP_RETAIN_RELEASES:-5}"
TEST_MODE="${YOSHOP_TEST_MODE:-0}"
MYSQL_CNF="$SHARED/mysql-client.cnf"
DB_NAME_FILE="$SHARED/db-name"
LOG_FILE="$SHARED/logs/release.log"
CLEANUP_PATH=""
ACTIVATING=0
ACTIVATION_OLD=""
ACTIVATION_ID=""
ACTIVATION_PACKAGE_SHA=""
ACTIVATION_MANIFEST_SHA=""

fail() { echo "ERROR: $*" >&2; exit 1; }
log() {
  local line
  line="$(date --iso-8601=seconds) $*"
  echo "$line" >&2
  if [[ -d "$SHARED/logs" ]]; then echo "$line" >>"$LOG_FILE"; fi
}
valid_release_id() { [[ "$1" =~ ^[0-9]{14}-[0-9a-f]{12}$ ]]; }
current_id() {
  if [[ -L "$CURRENT" ]]; then basename -- "$(readlink -f "$CURRENT")"; fi
}
previous_id() {
  if [[ -f "$STATE/previous" ]]; then cat "$STATE/previous"; fi
}

require_root() {
  if [[ "$TEST_MODE" != 1 && ${EUID:-$(id -u)} -ne 0 ]]; then
    fail 'server-release must run as root (normally through restricted sudo)'
  fi
}

initialize_layout() {
  if [[ "$TEST_MODE" == 1 ]]; then
    # Keep fixtures independent of production-only accounts while enforcing the
    # same directory modes used on the server.
    install -d -m 0711 "$ROOT"
    install -d -m 0750 "$INCOMING" "$RELEASES" "$STATE"
    install -d -m 0750 "$SHARED" "$SHARED/logs" "$SHARED/payment"
    install -d -m 0700 "$SHARED/backups"
    install -d -m 0770 "$SHARED/uploads" "$SHARED/runtime"
  else
    install -d -o root -g www-data -m 0711 "$ROOT"
    install -d -o deployer -g www-data -m 0750 "$INCOMING"
    install -d -o root -g www-data -m 0750 "$RELEASES" "$STATE"
    install -d -o root -g www-data -m 0750 "$SHARED" "$SHARED/logs" "$SHARED/payment"
    install -d -o root -g root -m 0700 "$SHARED/backups"
    install -d -o www-data -g www-data -m 0770 "$SHARED/uploads" "$SHARED/runtime"
  fi
  touch "$LOCK"
  chmod 0640 "$LOCK"
}

fail_closed_services() {
  [[ "$TEST_MODE" == 1 ]] || systemctl stop yoshop2.0-timer.service >/dev/null 2>&1 || true
}

on_exit() {
  local exit_code=$?
  if [[ "$ACTIVATING" == 1 ]]; then
    if [[ -n "$ACTIVATION_OLD" && -d "$RELEASES/$ACTIVATION_OLD" ]]; then
      switch_current "$RELEASES/$ACTIVATION_OLD" || true
      write_state_value current "$ACTIVATION_OLD" || true
      restart_services || true
    else
      rm -f -- "$CURRENT" "$STATE/current"
      fail_closed_services
    fi
    if [[ -n "$ACTIVATION_ID" && -d "$RELEASES/$ACTIVATION_ID" ]]; then
      write_prepared_state \
        "$ACTIVATION_ID" "$ACTIVATION_PACKAGE_SHA" "$ACTIVATION_MANIFEST_SHA" || true
    fi
    log "interrupted activation restored current to ${ACTIVATION_OLD:-none}"
  fi
  if [[ -n "$CLEANUP_PATH" ]]; then rm -rf -- "$CLEANUP_PATH"; fi
  return "$exit_code"
}
trap on_exit EXIT

validate_archive() {
  local archive="$1"
  python3 - "$archive" <<'PY'
import posixpath
import sys
import tarfile
from pathlib import PurePosixPath

archive = sys.argv[1]
with tarfile.open(archive, "r:gz") as handle:
    members = handle.getmembers()
    if not members:
        raise SystemExit("archive is empty")
    for member in members:
        name = member.name
        path = PurePosixPath(name)
        normalized = posixpath.normpath(name)
        if not name or path.is_absolute() or ".." in path.parts or normalized.startswith("../"):
            raise SystemExit(f"unsafe archive path: {name}")
        # Release archives are generated from regular files/directories. Rejecting links and
        # special files prevents link-parent extraction escapes; shared links are added later.
        if member.issym() or member.islnk() or member.isdev() or member.isfifo():
            raise SystemExit(f"unsafe archive member type: {name}")
PY
}

link_shared_data() {
  local release="$1"
  local app="$release/$APP_SUBDIR"
  [[ -d "$app" ]] || fail "release misses $APP_SUBDIR"
  rm -rf "$app/.env" "$app/runtime" "$app/public/uploads" "$app/data/payment"
  install -d -m 0755 "$app/public" "$app/data"
  ln -s "$SHARED/.env" "$app/.env"
  ln -s "$SHARED/runtime" "$app/runtime"
  ln -s "$SHARED/uploads" "$app/public/uploads"
  ln -s "$SHARED/payment" "$app/data/payment"
}

verify_shared_data() {
  local release="$1" app
  app="$release/$APP_SUBDIR"
  [[ -L "$app/.env" && "$(readlink "$app/.env")" == "$SHARED/.env" ]] || fail 'shared .env link is invalid'
  [[ -L "$app/runtime" && "$(readlink "$app/runtime")" == "$SHARED/runtime" ]] || fail 'shared runtime link is invalid'
  [[ -L "$app/public/uploads" && "$(readlink "$app/public/uploads")" == "$SHARED/uploads" ]] || \
    fail 'shared uploads link is invalid'
  [[ -L "$app/data/payment" && "$(readlink "$app/data/payment")" == "$SHARED/payment" ]] || \
    fail 'shared payment link is invalid'
}

verify_release() {
  local release="$1" release_id="$2"
  local app="$release/$APP_SUBDIR"
  [[ -f "$app/think" && ! -L "$app/think" && -f "$app/public/index.php" && ! -L "$app/public/index.php" ]] || \
    fail 'backend entry points missing or invalid'
  [[ -f "$app/vendor/autoload.php" && ! -L "$app/vendor/autoload.php" ]] || \
    fail 'PHP vendor is missing from release'
  [[ -f "$app/public/index.html" && -f "$app/public/admin/index.html" && -f "$app/public/store/index.html" ]] || \
    fail 'one or more frontend builds are missing'
  [[ -f "$release/release-manifest.json" && ! -L "$release/release-manifest.json" ]] || \
    fail 'release manifest missing or invalid'
  python3 - "$release" "$release_id" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

root = Path(sys.argv[1])
manifest = json.load((root / "release-manifest.json").open(encoding="utf-8"))
if manifest.get("schema") != 1 or manifest.get("release_id") != sys.argv[2]:
    raise SystemExit("manifest schema/release_id mismatch")
files = manifest.get("files")
if not isinstance(files, list) or manifest.get("file_count") != len(files) or len(files) < 100:
    raise SystemExit("manifest file list/count is invalid")
seen = set()
for record in files:
    if not isinstance(record, dict):
        raise SystemExit("manifest file record is invalid")
    relative = record.get("path")
    if not isinstance(relative, str):
        raise SystemExit("manifest path is invalid")
    parts = Path(relative).parts
    path = root / relative
    if (not relative or relative.startswith("/") or ".." in parts or relative in seen
            or path.is_symlink() or not path.is_file()):
        raise SystemExit(f"manifest path invalid or missing: {relative}")
    seen.add(relative)
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    if digest != record.get("sha256") or path.stat().st_size != record.get("size"):
        raise SystemExit(f"manifest checksum/size mismatch: {relative}")
actual = {
    path.relative_to(root).as_posix()
    for path in root.rglob("*")
    if path.is_file() and not path.is_symlink() and path != root / "release-manifest.json"
}
if actual != seen:
    difference = sorted(actual ^ seen)
    raise SystemExit(f"manifest does not exactly cover release files: {difference[:3]}")
PY
  php -l "$app/public/index.php" >/dev/null
  verify_shared_data "$release"
}

seal_release() {
  local release="$1"
  chown -R root:www-data "$release" 2>/dev/null || chown -R root:root "$release" 2>/dev/null || true
  find "$release" -type d -exec chmod 0555 {} +
  find "$release" -type f -exec chmod 0444 {} +
  chmod 0555 "$release/$APP_SUBDIR/think"
}

verify_sealed_release() {
  local release="$1" writable
  writable="$(find "$release" -xdev \( -type f -o -type d \) -perm /222 -print -quit)"
  [[ -z "$writable" ]] || fail "prepared release is mutable: $writable"
}

backup_database() {
  [[ "$TEST_MODE" != 1 ]] || return 0
  [[ -s "$MYSQL_CNF" && -s "$DB_NAME_FILE" ]] || fail 'database client config/db-name missing'
  local database timestamp output
  database="$(cat "$DB_NAME_FILE")"
  [[ "$database" =~ ^[A-Za-z0-9_]+$ ]] || fail 'invalid database name'
  timestamp="$(date +%Y%m%d-%H%M%S)"
  output="$SHARED/backups/${database}-${timestamp}.sql.gz"
  mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --routines --triggers \
    --events --hex-blob --default-character-set=utf8mb4 "$database" | gzip -9 >"$output"
  sha256sum "$output" >"$output.sha256"
  log "database backup created: $output"
}

apply_migrations() {
  local release="$1" migration checksum escaped existing database
  shopt -s nullglob
  local migrations=("$release"/migrations/*.sql)
  ((${#migrations[@]})) || return 0
  [[ "$TEST_MODE" != 1 ]] || { log 'test mode: migrations skipped'; return 0; }
  [[ -s "$MYSQL_CNF" && -s "$DB_NAME_FILE" ]] || fail 'migration DB config missing'
  database="$(cat "$DB_NAME_FILE")"
  mysql --defaults-extra-file="$MYSQL_CNF" "$database" <<'SQL'
CREATE TABLE IF NOT EXISTS `yoshop_deploy_migrations` (
  `migration` varchar(190) NOT NULL,
  `sha256` char(64) NOT NULL,
  `applied_at` datetime NOT NULL,
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
  for migration in "${migrations[@]}"; do
    local name
    name="$(basename "$migration")"
    [[ "$name" =~ ^[0-9]{4}_[A-Za-z0-9_-]+\.sql$ ]] || fail "invalid migration filename: $name"
    checksum="$(sha256sum "$migration" | awk '{print $1}')"
    escaped="${name//\'/\'\'}"
    existing="$(mysql --defaults-extra-file="$MYSQL_CNF" --batch --skip-column-names "$database" \
      -e "SELECT sha256 FROM yoshop_deploy_migrations WHERE migration='$escaped'")"
    if [[ -n "$existing" ]]; then
      [[ "$existing" == "$checksum" ]] || fail "migration checksum changed: $name"
      continue
    fi
    log "applying migration: $name"
    mysql --defaults-extra-file="$MYSQL_CNF" "$database" <"$migration"
    mysql --defaults-extra-file="$MYSQL_CNF" "$database" -e \
      "INSERT INTO yoshop_deploy_migrations(migration,sha256,applied_at) VALUES('$escaped','$checksum',NOW())"
  done
}

switch_current() {
  local target="$1" temporary="$ROOT/.current.$$.tmp"
  ln -s "$target" "$temporary"
  mv -Tf "$temporary" "$CURRENT"
}

restart_services() {
  [[ "$TEST_MODE" != 1 ]] || return 0
  systemctl reload php8.3-fpm
  systemctl restart yoshop2.0-timer.service
  nginx -t
  systemctl reload nginx
}

health_check() {
  local release="$1"
  [[ -f "$release/$APP_SUBDIR/public/index.html" ]] || return 1
  if [[ "$TEST_MODE" == 1 ]]; then
    [[ "$(basename -- "$release")" != "${YOSHOP_TEST_HEALTH_FAIL_RELEASE:-}" ]]
    return
  fi
  curl --fail --silent --show-error --max-time 20 \
    --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" >/dev/null
  curl --fail --silent --show-error --max-time 20 "$HEALTH_URL" >/dev/null
}

validated_current_id() {
  if [[ ! -e "$CURRENT" && ! -L "$CURRENT" ]]; then return 0; fi
  [[ -L "$CURRENT" ]] || fail 'invalid current state: current is not a symlink'
  local target release_id
  target="$(readlink -f "$CURRENT")" || fail 'invalid current state: broken symlink'
  [[ "$(dirname "$target")" == "$RELEASES" && -d "$target" ]] || fail 'invalid current state: target is outside releases'
  release_id="$(basename "$target")"
  valid_release_id "$release_id" || fail 'invalid current state: bad release id'
  printf '%s\n' "$release_id"
}

load_prepared_state() {
  [[ -f "$PREPARED_STATE" && ! -L "$PREPARED_STATE" ]] || fail 'no valid prepared candidate'
  local output
  output="$(python3 - "$PREPARED_STATE" <<'PY'
import json
import re
import sys

try:
    data = json.load(open(sys.argv[1], encoding="utf-8"))
except (OSError, ValueError) as exc:
    raise SystemExit(f"invalid prepared state: {exc}")
release_id = data.get("release_id")
package_sha = data.get("package_sha256")
manifest_sha = data.get("manifest_sha256")
if data.get("schema") != 1 or data.get("state") != "prepared":
    raise SystemExit("invalid prepared state schema/state")
if not isinstance(release_id, str) or not re.fullmatch(r"[0-9]{14}-[0-9a-f]{12}", release_id):
    raise SystemExit("invalid prepared release id")
for name, value in (("package", package_sha), ("manifest", manifest_sha)):
    if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{64}", value):
        raise SystemExit(f"invalid prepared {name} SHA-256")
print(release_id)
print(package_sha)
print(manifest_sha)
PY
)" || fail 'invalid prepared state'
  mapfile -t PREPARED_FIELDS <<<"$output"
  ((${#PREPARED_FIELDS[@]} == 3)) || fail 'invalid prepared state fields'
}

write_prepared_state() {
  local release_id="$1" package_sha="$2" manifest_sha="$3" temporary="$STATE/.prepared.$$.tmp"
  python3 - "$temporary" "$release_id" "$package_sha" "$manifest_sha" <<'PY'
import datetime
import json
import os
import sys

path, release_id, package_sha, manifest_sha = sys.argv[1:]
data = {
    "schema": 1,
    "state": "prepared",
    "release_id": release_id,
    "package_sha256": package_sha,
    "manifest_sha256": manifest_sha,
    "prepared_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
}
with open(path, "w", encoding="utf-8") as handle:
    json.dump(data, handle, separators=(",", ":"), sort_keys=True)
    handle.write("\n")
os.chmod(path, 0o640)
PY
  mv -f "$temporary" "$PREPARED_STATE"
}

write_state_value() {
  local name="$1" value="$2" temporary
  temporary="$STATE/.$name.$$.tmp"
  printf '%s\n' "$value" >"$temporary"
  chmod 0640 "$temporary"
  mv -f "$temporary" "$STATE/$name"
}

cleanup_old_releases() {
  local current previous prepared candidate
  current="$(current_id)"; previous="$(previous_id)"; prepared=""
  if [[ -f "$PREPARED_STATE" ]]; then
    local output
    output="$(python3 - "$PREPARED_STATE" 2>/dev/null <<'PY' || true
import json,sys
try: print(json.load(open(sys.argv[1], encoding="utf-8")).get("release_id", ""))
except (OSError, ValueError): pass
PY
)"
    prepared="$output"
  fi
  mapfile -t all < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
  local kept=0
  for candidate in "${all[@]}"; do
    if [[ "$candidate" == "$current" || "$candidate" == "$previous" || "$candidate" == "$prepared" || $kept -lt $RETAIN ]]; then
      ((kept+=1)); continue
    fi
    valid_release_id "$candidate" || continue
    rm -rf -- "${RELEASES:?}/$candidate"
    log "removed old release: $candidate"
  done
}

prepare_release() {
  [[ $# -eq 3 ]] || fail 'prepare requires: release-id package-name sha256'
  local release_id="$1" package_name="$2" expected_sha="$3"
  valid_release_id "$release_id" || fail 'invalid release id'
  [[ "$package_name" == "yoshop-$release_id.tar.gz" && "$package_name" != */* ]] || fail 'invalid package name'
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'invalid SHA-256'
  [[ ! -e "$PREPARED_STATE" && ! -L "$PREPARED_STATE" ]] || \
    fail 'a prepared candidate already exists; inspect status and activate it first'
  local archive="$INCOMING/$package_name" actual target temporary manifest_sha
  [[ -f "$archive" && ! -L "$archive" ]] || fail "incoming package missing: $archive"
  actual="$(sha256sum "$archive" | awk '{print $1}')"
  [[ "$actual" == "$expected_sha" ]] || fail 'incoming package SHA-256 mismatch'
  validate_archive "$archive"
  target="$RELEASES/$release_id"
  [[ ! -e "$target" && ! -L "$target" ]] || fail "release already exists: $release_id"
  temporary="$RELEASES/.${release_id}.extracting"
  rm -rf -- "$temporary"
  install -d -m 0750 "$temporary"
  CLEANUP_PATH="$temporary"
  tar -xzf "$archive" -C "$temporary" --no-same-owner --no-same-permissions
  link_shared_data "$temporary"
  verify_release "$temporary" "$release_id"
  seal_release "$temporary"
  verify_sealed_release "$temporary"
  manifest_sha="$(sha256sum "$temporary/release-manifest.json" | awk '{print $1}')"
  mv "$temporary" "$target"
  CLEANUP_PATH="$target"
  write_prepared_state "$release_id" "$expected_sha" "$manifest_sha"
  CLEANUP_PATH=""
  rm -f -- "$archive" "$INCOMING/$package_name.sha256"
  log "prepared immutable release: $release_id"
}

verify_prepared_candidate() {
  local requested="$1" target manifest_sha
  load_prepared_state
  [[ "${PREPARED_FIELDS[0]}" == "$requested" ]] || \
    fail "prepared candidate is ${PREPARED_FIELDS[0]}, not $requested"
  target="$RELEASES/$requested"
  [[ -d "$target" && ! -L "$target" ]] || fail 'prepared candidate directory is missing or invalid'
  verify_sealed_release "$target"
  manifest_sha="$(sha256sum "$target/release-manifest.json" | awk '{print $1}')"
  [[ "$manifest_sha" == "${PREPARED_FIELDS[2]}" ]] || fail 'prepared manifest changed after verification'
  verify_release "$target" "$requested"
}

activate_release() {
  [[ $# -eq 1 ]] || fail 'activate requires: release-id'
  local release_id="$1" target old
  valid_release_id "$release_id" || fail 'invalid release id'
  verify_prepared_candidate "$release_id"
  target="$RELEASES/$release_id"
  old="$(validated_current_id)"
  [[ "$old" != "$release_id" ]] || fail 'prepared candidate is already current'
  if compgen -G "$target/migrations/*.sql" >/dev/null; then backup_database; fi
  apply_migrations "$target"

  ACTIVATION_OLD="$old"
  ACTIVATION_ID="$release_id"
  ACTIVATION_PACKAGE_SHA="${PREPARED_FIELDS[1]}"
  ACTIVATION_MANIFEST_SHA="${PREPARED_FIELDS[2]}"
  ACTIVATING=1
  switch_current "$target"
  if ! restart_services || ! health_check "$target"; then
    log "health failed for $release_id; restoring current to ${old:-none}"
    if [[ -n "$old" && -d "$RELEASES/$old" ]]; then
      switch_current "$RELEASES/$old"
      restart_services || true
    else
      rm -f -- "$CURRENT"
      fail_closed_services
    fi
    ACTIVATING=0
    fail 'activation health check failed; previous current restored (candidate remains prepared)'
  fi
  if [[ -n "$old" ]]; then
    write_state_value previous "$old"
  else
    rm -f -- "$STATE/previous"
  fi
  write_state_value current "$release_id"
  rm -f -- "$PREPARED_STATE"
  ACTIVATING=0
  ACTIVATION_ID=""
  ACTIVATION_PACKAGE_SHA=""
  ACTIVATION_MANIFEST_SHA=""
  cleanup_old_releases
  log "activated release: $release_id (previous: ${old:-none})"
}

install_release() {
  [[ $# -eq 3 ]] || fail 'install requires: release-id package-name sha256'
  local release_id="$1"
  prepare_release "$@"
  activate_release "$release_id"
}

rollback_release() {
  local current previous
  current="$(validated_current_id)"; previous="$(previous_id)"
  [[ -n "$current" && -n "$previous" ]] || fail 'no valid previous release'
  valid_release_id "$previous" || fail 'invalid previous release state'
  [[ -d "$RELEASES/$previous" && ! -L "$RELEASES/$previous" ]] || fail 'no valid previous release'
  verify_sealed_release "$RELEASES/$previous"
  verify_release "$RELEASES/$previous" "$previous"
  ACTIVATION_OLD="$current"
  ACTIVATING=1
  switch_current "$RELEASES/$previous"
  if ! restart_services || ! health_check "$RELEASES/$previous"; then
    switch_current "$RELEASES/$current"
    restart_services || true
    ACTIVATING=0
    fail 'rollback target failed health check; restored current release'
  fi
  write_state_value previous "$current"
  write_state_value current "$previous"
  ACTIVATING=0
  log "rolled back code: $current -> $previous (database not changed)"
}

status_json() {
  local disk
  disk="$(df -P "$ROOT" | awk 'NR==2 {print $5}')"
  python3 - "$ROOT" "$disk" <<'PY'
import hashlib
import json
import os
import re
import stat
import sys
from pathlib import Path

root = Path(sys.argv[1])
disk = sys.argv[2]
releases = root / "releases"
state_dir = root / "state"
valid_id = re.compile(r"^[0-9]{14}-[0-9a-f]{12}$")
errors = []
current = None
current_state = "absent"
current_path = root / "current"
if current_path.is_symlink():
    try:
        target = current_path.resolve(strict=True)
        if target.parent != releases or not valid_id.fullmatch(target.name) or not target.is_dir():
            raise ValueError("target is not a valid release")
        current = target.name
        current_state = "active"
    except (OSError, ValueError) as exc:
        current_state = "invalid"
        errors.append(f"invalid current state: {exc}")
elif current_path.exists():
    current_state = "invalid"
    errors.append("invalid current state: current is not a symlink")

recorded_current = state_dir / "current"
if recorded_current.is_file():
    value = recorded_current.read_text(encoding="utf-8").strip()
    if not valid_id.fullmatch(value) or value != current:
        errors.append("recorded current does not match current symlink")

previous = None
previous_path = state_dir / "previous"
if previous_path.is_file():
    value = previous_path.read_text(encoding="utf-8").strip()
    if valid_id.fullmatch(value) and (releases / value).is_dir():
        previous = value
    else:
        errors.append("invalid previous release state")

prepared = None
prepared_path = state_dir / "prepared.json"
if prepared_path.exists() or prepared_path.is_symlink():
    try:
        if prepared_path.is_symlink() or not prepared_path.is_file():
            raise ValueError("prepared state is not a regular file")
        data = json.loads(prepared_path.read_text(encoding="utf-8"))
        release_id = data.get("release_id")
        package_sha = data.get("package_sha256")
        manifest_sha = data.get("manifest_sha256")
        if data.get("schema") != 1 or data.get("state") != "prepared":
            raise ValueError("bad schema/state")
        if not isinstance(release_id, str) or not valid_id.fullmatch(release_id):
            raise ValueError("bad release id")
        if not isinstance(package_sha, str) or not re.fullmatch(r"[0-9a-f]{64}", package_sha):
            raise ValueError("bad package SHA-256")
        if not isinstance(manifest_sha, str) or not re.fullmatch(r"[0-9a-f]{64}", manifest_sha):
            raise ValueError("bad manifest SHA-256")
        candidate = releases / release_id
        if release_id == current:
            raise ValueError("prepared candidate is already current")
        if candidate.is_symlink() or not candidate.is_dir():
            raise ValueError("candidate directory missing or invalid")
        manifest = candidate / "release-manifest.json"
        actual_manifest_sha = hashlib.sha256(manifest.read_bytes()).hexdigest()
        if actual_manifest_sha != manifest_sha:
            raise ValueError("candidate manifest changed")
        release_manifest = json.loads(manifest.read_text(encoding="utf-8"))
        records = release_manifest.get("files")
        if (release_manifest.get("schema") != 1
                or release_manifest.get("release_id") != release_id
                or not isinstance(records, list)
                or release_manifest.get("file_count") != len(records)):
            raise ValueError("candidate release manifest is invalid")
        seen = set()
        for record in records:
            relative = record.get("path") if isinstance(record, dict) else None
            if (not isinstance(relative, str) or not relative or relative.startswith("/")
                    or ".." in Path(relative).parts or relative in seen):
                raise ValueError("candidate release manifest path is invalid")
            seen.add(relative)
            path = candidate / relative
            if path.is_symlink() or not path.is_file():
                raise ValueError(f"candidate file missing or invalid: {relative}")
            if (path.stat().st_size != record.get("size")
                    or hashlib.sha256(path.read_bytes()).hexdigest() != record.get("sha256")):
                raise ValueError(f"candidate file changed: {relative}")
        actual = {
            path.relative_to(candidate).as_posix()
            for path in candidate.rglob("*")
            if path.is_file() and not path.is_symlink() and path != manifest
        }
        if actual != seen:
            raise ValueError("candidate files do not match manifest")
        shared_links = {
            candidate / "yoshop2.0/.env": root / "shared/.env",
            candidate / "yoshop2.0/runtime": root / "shared/runtime",
            candidate / "yoshop2.0/public/uploads": root / "shared/uploads",
            candidate / "yoshop2.0/data/payment": root / "shared/payment",
        }
        for link, expected in shared_links.items():
            if not link.is_symlink() or os.readlink(link) != str(expected):
                raise ValueError(f"candidate shared link is invalid: {link.name}")
        mutable = next((str(path.relative_to(candidate)) for path in candidate.rglob("*")
                        if not path.is_symlink() and path.stat().st_mode & (stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH)), None)
        if mutable:
            raise ValueError(f"candidate is mutable: {mutable}")
        prepared = {
            "state": "prepared",
            "release_id": release_id,
            "package_sha256": package_sha,
            "manifest_sha256": manifest_sha,
            "verified": True,
        }
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        prepared = {"state": "invalid", "verified": False, "error": str(exc)}
        errors.append(f"invalid prepared state: {exc}")

print(json.dumps({
    "ok": not errors,
    "current": current,
    "current_state": current_state,
    "previous": previous,
    "prepared": prepared,
    "disk_used": disk,
    "errors": errors,
}, separators=(",", ":"), sort_keys=True))
PY
}

main() {
  require_root
  initialize_layout
  exec 9>"$LOCK"
  flock -w 120 9 || fail 'another release operation holds the lock'
  local command="${1:-}"
  shift || true
  case "$command" in
    prepare) prepare_release "$@"; status_json ;;
    activate) activate_release "$@"; status_json ;;
    install) install_release "$@"; status_json ;;
    rollback) [[ $# -eq 0 ]] || fail 'rollback takes no arguments'; rollback_release; status_json ;;
    status) [[ $# -eq 0 ]] || fail 'status takes no arguments'; status_json ;;
    *) fail 'usage: server-release.sh {prepare <id> <package> <sha256>|activate <id>|install <id> <package> <sha256>|rollback|status}' ;;
  esac
}
main "$@"
