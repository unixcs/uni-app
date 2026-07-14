#!/usr/bin/env bash
# Shared validation for the production backup and restore entry points.
# This file is sourced; callers enable strict mode themselves.

backup_fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

backup_require_cmd() {
  command -v "$1" >/dev/null 2>&1 || backup_fail "missing required command: $1"
}

backup_init_paths() {
  YOSHOP_ROOT="${YOSHOP_ROOT:-/srv/yoshop}"
  YOSHOP_SHARED="$YOSHOP_ROOT/shared"
  YOSHOP_BACKUPS="$YOSHOP_SHARED/backups"
  YOSHOP_MYSQL_CNF="$YOSHOP_SHARED/mysql-client.cnf"
  YOSHOP_DB_NAME_FILE="$YOSHOP_SHARED/db-name"
  YOSHOP_TEST_MODE="${YOSHOP_BACKUP_TEST_MODE:-0}"
}

backup_require_root() {
  if [[ "$YOSHOP_TEST_MODE" == 1 ]]; then
    [[ "$YOSHOP_ROOT" != /srv/yoshop ]] || \
      backup_fail 'test mode refuses the production root'
    return
  fi
  [[ ${EUID:-$(id -u)} -eq 0 ]] || backup_fail 'must run as root'
  [[ "$YOSHOP_ROOT" == /srv/yoshop ]] || \
    backup_fail 'custom YOSHOP_ROOT is allowed only in test mode'
}

backup_validate_mode_no_group_or_other() {
  local path="$1" mode
  mode="$(stat -c '%a' -- "$path")" || backup_fail "cannot inspect protected file: $path"
  (( (8#$mode & 077) == 0 )) || backup_fail "protected file must not be accessible by group/other: $path"
}

backup_validate_regular_file() {
  local path="$1"
  [[ -f "$path" && ! -L "$path" ]] || backup_fail "required regular file is missing or unsafe: $path"
  if [[ "$YOSHOP_TEST_MODE" != 1 ]]; then
    [[ "$(stat -c '%u' -- "$path")" == 0 ]] || backup_fail "protected file must be owned by root: $path"
  fi
}

backup_load_database_name() {
  local -a lines=()
  backup_validate_regular_file "$YOSHOP_DB_NAME_FILE"
  backup_validate_mode_no_group_or_other "$YOSHOP_DB_NAME_FILE"
  [[ "$(stat -c '%a' -- "$YOSHOP_DB_NAME_FILE")" == 600 ]] || backup_fail 'db-name mode must be 0600'
  mapfile -t lines < "$YOSHOP_DB_NAME_FILE"
  ((${#lines[@]} == 1)) || backup_fail 'db-name must contain exactly one line'
  YOSHOP_DATABASE="${lines[0]}"
  [[ "$YOSHOP_DATABASE" =~ ^[A-Za-z0-9_]{1,64}$ ]] || backup_fail 'db-name contains unsafe characters'
}

backup_validate_mysql_defaults() {
  backup_validate_regular_file "$YOSHOP_MYSQL_CNF"
  backup_validate_mode_no_group_or_other "$YOSHOP_MYSQL_CNF"
  [[ "$(stat -c '%a' -- "$YOSHOP_MYSQL_CNF")" == 600 ]] || backup_fail 'mysql-client.cnf mode must be 0600'
  grep -Eq '^[[:space:]]*\[client\][[:space:]]*$' "$YOSHOP_MYSQL_CNF" || \
    backup_fail 'mysql-client.cnf must contain a [client] section'
}

backup_validate_layout() {
  [[ -d "$YOSHOP_ROOT" && ! -L "$YOSHOP_ROOT" ]] || backup_fail "production root is missing or unsafe: $YOSHOP_ROOT"
  [[ -d "$YOSHOP_SHARED" && ! -L "$YOSHOP_SHARED" ]] || backup_fail "shared directory is missing or unsafe: $YOSHOP_SHARED"
  [[ -d "$YOSHOP_BACKUPS" && ! -L "$YOSHOP_BACKUPS" ]] || backup_fail "backup directory is missing or unsafe: $YOSHOP_BACKUPS"
  if [[ "$YOSHOP_TEST_MODE" != 1 ]]; then
    [[ "$(stat -c '%u' -- "$YOSHOP_BACKUPS")" == 0 ]] || backup_fail 'backup directory must be owned by root'
  fi
  chmod 0700 "$YOSHOP_BACKUPS"
  backup_validate_mysql_defaults
  backup_load_database_name
}

backup_mysql_args() {
  # Populated for the sourcing entry point.
  # shellcheck disable=SC2034
  YOSHOP_MYSQL_ARGS=(
    "--defaults-extra-file=$YOSHOP_MYSQL_CNF"
    --batch
    --skip-column-names
    --default-character-set=utf8mb4
  )
}
