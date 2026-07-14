#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'
umask 027

usage() {
  cat <<'USAGE'
Usage: sudo renew-acme-cert.sh [certbot-renew-options]

Examples:
  sudo renew-acme-cert.sh --dry-run --no-random-sleep-on-renew
  sudo renew-acme-cert.sh

Arguments are forwarded as an array to `certbot renew`; do not put secrets in
arguments. The command validates Nginx before reloading it.
USAGE
}

if [[ ${1:-} == --help ]]; then usage; exit 0; fi
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
for command in certbot nginx systemctl; do
  command -v "$command" >/dev/null 2>&1 || { echo "Missing required command: $command" >&2; exit 1; }
done

certbot renew --quiet "$@"
nginx -t
systemctl reload nginx
printf '%s\n' 'ACME renewal completed; Nginx configuration validated and reloaded.'
