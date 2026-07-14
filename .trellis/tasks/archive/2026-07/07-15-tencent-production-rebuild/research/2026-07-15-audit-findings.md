# Audit findings (read-only, 2026-07-15)

## Local repository

- Working tree: 65 changes at planning start; `.git` about 50 MiB; 34 commits.
- Workspace about 1.5 GiB, mostly required `node_modules` (about 1.28 GiB) and PHP vendor (about 49 MiB).
- History contains about 482 `unpackage/dist` files, generated admin/store assets and 12 uploads.
- No real `.env` path found in Git history; sensitive generated/payment paths still require scanning.
- Local DB: 64 tables; 11 goods, 87 orders, 45 refunds, 150 payment trades, 9 users, 326 balance logs. Uploads: 28 files/about 2.9 MiB.
- Payment PEM files exist under `yoshop2.0/data/payment` and must be excluded/moved/protected.

## Tencent host

- `124.222.144.181`, Ubuntu 22.04, PHP 8.3, MySQL 8, Redis 6, Nginx 1.18, rsync 3.2.7.
- Disk: about 69 GiB, observed at 94% with about 4.1 GiB free.
- Root cause: `yoshop2.0-timer.service` master repeatedly respawns Workerman children that fail with `Implicit conversion from float ... to int loses precision` under PHP 8.3.
- Growth: Workerman log about 19 GiB, syslog/current+rotated about 32 GiB, journal about 3.9 GiB.
- No confirmed successful intrusion: password SSH disabled, only one authorized ED25519 fingerprint matching current WSL key, successful source IPs user-approved, no unknown public listeners/miners/containers found.
- Active SSH pre-auth scans exist and can exhaust handshake capacity; UFW inactive and Fail2ban absent.
- Docker/containerd must remain. Existing `static-site` container has been stopped since 2026-06-07; host Nginx currently serves the filing page.
- `ModemManager`, `udisks2`, `upower`, `PackageKit`, `fwupd` appear to be image/default packages rather than intrusion artifacts; remove only after dependency simulation.
- Extra accounts: `lighthouse` locked/never logged but NOPASSWD sudo; `f` password-set, never logged, no sudo. Review/harden during rebuild.

## Confirmed decisions

The user approved all requirements in the parent PRD, including atomic releases, full local builds, sanitized initial data, Redis cache/session, maintenance mode, bounded Timer failure, Git history preservation, same GitHub URL, no COS in this round, and no automatic SSH key rotation.
