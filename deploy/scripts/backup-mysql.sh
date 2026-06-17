#!/usr/bin/env bash
set -euo pipefail

IFS=$'\n\t'

# Minimal MySQL backup helper for production ops.
#
# Usage:
#   DB_NAME=yoshop DB_USER=root DB_PASS='***' ./deploy/scripts/backup-mysql.sh
#
# Required env vars:
#   DB_NAME   Database to dump
#
# Optional env vars:
#   DB_HOST   Default: 127.0.0.1
#   DB_PORT   Default: 3306
#   DB_USER   Default: root
#   DB_PASS   Optional password; if empty, mysql client auth is attempted via defaults
#   BACKUP_DIR Default: /opt/yoshop/backups

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

DB_NAME="${DB_NAME:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
BACKUP_DIR="${BACKUP_DIR:-/opt/yoshop/backups}"

if [[ -z "${DB_NAME}" ]]; then
  echo "DB_NAME is required." >&2
  exit 1
fi

require_cmd mysqldump
require_cmd gzip
require_cmd sha256sum
require_cmd mkdir

mkdir -p "${BACKUP_DIR}"

timestamp="$(date +%F_%H%M%S)"
backup_file="${BACKUP_DIR}/${DB_NAME}_${timestamp}.sql.gz"

dump_args=(
  --host="${DB_HOST}"
  --port="${DB_PORT}"
  --user="${DB_USER}"
  --single-transaction
  --routines
  --triggers
  --events
  --hex-blob
  --default-character-set=utf8mb4
  --set-gtid-purged=OFF
  "${DB_NAME}"
)

if [[ -n "${DB_PASS}" ]]; then
  export MYSQL_PWD="${DB_PASS}"
fi

mysqldump "${dump_args[@]}" | gzip -9 > "${backup_file}"
sha256sum "${backup_file}" > "${backup_file}.sha256"

echo "Backup created: ${backup_file}"
