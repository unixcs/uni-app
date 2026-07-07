#!/usr/bin/env bash
set -euo pipefail

IFS=$'\n\t'

# Tencent Cloud Ubuntu 22.04 bootstrap for OpenSpec task 4.1.
#
# Notes:
# - This intentionally installs PHP 8.3 packages because the existing repo docs
#   and deployment notes reference php8.3-fpm.
# - Ubuntu 22.04 does not ship PHP 8.3 in the default archive, so this script
#   adds the Ondřej Surý PHP PPA only to make those packages available.
# - This is a minimal infrastructure bootstrap, not a full application deploy.

APP_ROOT="/opt/yoshop/yoshop2.0"
PUBLIC_DIR="${APP_ROOT}/public"
WEB_USER="www-data"
WEB_GROUP="www-data"

require_root() {
  if [[ ${EUID} -ne 0 ]]; then
    echo "Please run this script as root or with sudo." >&2
    exit 1
  fi
}

run() {
  echo "+ $*"
  "$@"
}

require_root

export DEBIAN_FRONTEND=noninteractive

run apt-get update
run apt-get install -y software-properties-common ca-certificates lsb-release
run add-apt-repository -y ppa:ondrej/php
run apt-get update

# PHP 8.3 is the explicit target for this repo's docs and runtime hints.
run apt-get install -y \
  composer \
  nginx \
  mysql-server \
  redis-server \
  php8.3-cli \
  php8.3-common \
  php8.3-fpm \
  php8.3-mysql \
  php8.3-curl \
  php8.3-gd \
  php8.3-mbstring \
  php8.3-xml \
  php8.3-zip \
  php8.3-bcmath \
  php8.3-intl \
  php8.3-opcache \
  php8.3-redis

run systemctl enable --now nginx mysql redis-server php8.3-fpm

run install -d -o "${WEB_USER}" -g "${WEB_GROUP}" -m 0755 "${PUBLIC_DIR}" "${PUBLIC_DIR}/admin" "${PUBLIC_DIR}/store"
run install -d -o "${WEB_USER}" -g "${WEB_GROUP}" -m 0775 "${PUBLIC_DIR}/uploads" "${APP_ROOT}/runtime"

# Keep the application tree readable by the web user while preserving standard
# directory/file modes for future code deployment.
run chown -R "${WEB_USER}:${WEB_GROUP}" "${APP_ROOT}"
run find "${APP_ROOT}" -type d -exec chmod 0755 {} +
run find "${APP_ROOT}" -type f -exec chmod 0644 {} +
run chmod 0775 "${PUBLIC_DIR}/uploads" "${APP_ROOT}/runtime"

cat <<'EOF'

Bootstrap complete.

Next steps (manual secure tasks remain):
1) Copy deploy/env/yoshop2.0.env.example to /opt/yoshop/yoshop2.0/.env and fill in production values.
2) Run mysql_secure_installation, then create a dedicated database and application user.
3) Run `cd /opt/yoshop/yoshop2.0 && composer install` without `--no-scripts`.
4) Import the project SQL files as needed (deploy/sql/install.sql, then demo/patch SQL if required).
5) Place the Nginx site config from deploy/nginx/ into /etc/nginx/sites-available and enable it.
6) Verify php8.3-fpm is listening on /run/php/php8.3-fpm.sock and reload nginx after testing.
7) Review SSL, backups, firewall rules, and any external service credentials before go-live.

EOF
