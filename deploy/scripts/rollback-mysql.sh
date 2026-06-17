#!/usr/bin/env bash
set -euo pipefail

IFS=$'\n\t'

# Minimal MySQL rollback helper for production ops.
#
# Usage:
#   DB_NAME=yoshop DB_USER=root DB_PASS='***' ./deploy/scripts/rollback-mysql.sh /path/to/backup.sql.gz
#
# This restores the database from a dump file. It does not restore application
# code, uploaded assets, or web server config.

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

BACKUP_FILE="${1:-}"
DB_NAME="${DB_NAME:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DROP_AND_RECREATE="${DROP_AND_RECREATE:-1}"

if [[ -z "${BACKUP_FILE}" ]]; then
  echo "Usage: $0 /path/to/backup.sql.gz" >&2
  exit 1
fi

if [[ -z "${DB_NAME}" ]]; then
  echo "DB_NAME is required." >&2
  exit 1
fi

if [[ ! -f "${BACKUP_FILE}" ]]; then
  echo "Backup file not found: ${BACKUP_FILE}" >&2
  exit 1
fi

require_cmd mysql
require_cmd gzip

if [[ -n "${DB_PASS}" ]]; then
  export MYSQL_PWD="${DB_PASS}"
fi

mysql_base=(--host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" --default-character-set=utf8mb4)

if [[ "${DROP_AND_RECREATE}" == "1" ]]; then
  mysql "${mysql_base[@]}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
else
  mysql "${mysql_base[@]}" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
fi

gzip -dc "${BACKUP_FILE}" | mysql "${mysql_base[@]}" "${DB_NAME}"

echo "Rollback restore completed for database: ${DB_NAME}"
