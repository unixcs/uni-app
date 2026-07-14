#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'
umask 027

[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ -f /etc/os-release ]] || { echo 'Cannot identify OS.' >&2; exit 1; }
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 22.04 ]] || {
  echo "Expected Ubuntu 22.04, found ${PRETTY_NAME:-unknown}." >&2; exit 1;
}

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
RELEASE_SCRIPT="${RELEASE_SCRIPT:-$SCRIPT_DIR/server-release.sh}"
TIMER_UNIT="${TIMER_UNIT:-$SCRIPT_DIR/../systemd/yoshop2.0-timer.service}"
[[ -f "$RELEASE_SCRIPT" && -f "$TIMER_UNIT" ]] || {
  echo 'server-release.sh and timer unit must accompany bootstrap.' >&2; exit 1;
}

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends ufw fail2ban rsync curl ca-certificates

if ! id deployer >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash --user-group deployer
fi
passwd -l deployer >/dev/null
install -d -o deployer -g deployer -m 0700 /home/deployer/.ssh
[[ -s /root/.ssh/authorized_keys ]] || { echo 'Root authorized_keys is missing.' >&2; exit 1; }
install -o deployer -g deployer -m 0600 /root/.ssh/authorized_keys /home/deployer/.ssh/authorized_keys

# The deployer needs traverse-only access to reach its owned incoming directory.
# Child directories keep their own stricter ownership; 0711 does not allow
# listing or reading shared/release data.
install -d -o root -g www-data -m 0711 /srv/yoshop
install -d -o root -g www-data -m 0750 /srv/yoshop/releases /srv/yoshop/state
install -d -o deployer -g www-data -m 0750 /srv/yoshop/incoming
install -d -o root -g www-data -m 0750 /srv/yoshop/shared /srv/yoshop/shared/payment /srv/yoshop/shared/logs
install -d -o www-data -g www-data -m 0770 /srv/yoshop/shared/uploads /srv/yoshop/shared/runtime
install -d -o root -g root -m 0700 /srv/yoshop/shared/backups
install -o root -g root -m 0755 "$RELEASE_SCRIPT" /usr/local/sbin/yoshop-release

cat >/etc/sudoers.d/yoshop-deployer <<'SUDO'
Defaults:deployer !requiretty
Cmnd_Alias YOSHOP_RELEASE = /usr/local/sbin/yoshop-release *
deployer ALL=(root) NOPASSWD: YOSHOP_RELEASE
SUDO
chmod 0440 /etc/sudoers.d/yoshop-deployer
visudo -cf /etc/sudoers.d/yoshop-deployer

cat >/etc/ssh/sshd_config.d/60-yoshop-hardening.conf <<'SSH'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
PubkeyAuthentication yes
LoginGraceTime 30
MaxAuthTries 3
MaxStartups 20:30:60
SSH
sshd -t
systemctl reload ssh

cat >/etc/fail2ban/jail.d/sshd.local <<'JAIL'
[sshd]
enabled = true
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
JAIL
systemctl enable --now fail2ban

ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP/ACME'
ufw allow 443/tcp comment 'HTTPS'
ufw --force enable

install -d -m 0755 /etc/systemd/journald.conf.d
cat >/etc/systemd/journald.conf.d/60-yoshop-limits.conf <<'JOURNAL'
[Journal]
SystemMaxUse=512M
SystemKeepFree=2G
MaxRetentionSec=14day
RateLimitIntervalSec=30s
RateLimitBurst=5000
JOURNAL
systemctl restart systemd-journald
journalctl --vacuum-size=512M >/dev/null || true

cat >/etc/logrotate.d/yoshop <<'ROTATE'
/srv/yoshop/shared/logs/*.log /srv/yoshop/shared/runtime/workerman/*.log {
    daily
    rotate 14
    size 20M
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
ROTATE

if ! swapon --show=NAME --noheadings | grep -q .; then
  if [[ ! -f /swapfile ]]; then
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=progress
    chmod 0600 /swapfile
    mkswap /swapfile >/dev/null
  fi
  swapon /swapfile
fi
grep -qE '^/swapfile\s' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab

install -o root -g root -m 0644 "$TIMER_UNIT" /etc/systemd/system/yoshop2.0-timer.service
systemctl daemon-reload
systemctl disable --now yoshop2.0-timer.service >/dev/null 2>&1 || true

printf '%s\n' 'foundation bootstrap complete; timer intentionally remains disabled until a verified release is active'
