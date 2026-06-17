#!/usr/bin/env bash
set -euo pipefail

IFS=$'\n\t'

# Minimal ACME renewal helper for production ops.
#
# Usage:
#   sudo ./deploy/scripts/renew-acme-cert.sh
#
# Optional env vars:
#   NGINX_SERVICE   Default: nginx
#   CERTBOT_ARGS    Additional args passed to certbot renew

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

NGINX_SERVICE="${NGINX_SERVICE:-nginx}"
CERTBOT_ARGS="${CERTBOT_ARGS:-}"

require_cmd certbot
require_cmd systemctl

if ! certbot renew --quiet ${CERTBOT_ARGS}; then
  echo "ACME renewal failed." >&2
  exit 1
fi

systemctl reload "${NGINX_SERVICE}"
echo "ACME renewal check completed; ${NGINX_SERVICE} reloaded."
