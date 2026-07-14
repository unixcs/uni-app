# Tencent foundation rebuild evidence

## Recovery evidence

Before mutation, a root-only remote audit/config archive was created, copied to
WSL, SHA-256 verified and tar-tested:

`/root/yoshop-migration-backups/tencent-pre-rebuild-20260715-023638/20260715-023638.tar.gz`

It contains system/process/listener/package/account/cron/Docker/service evidence,
Nginx/Certbot/SSH/systemd/UFW config, authorized key, old production env/payment
material and checksums. No cloud-console disk snapshot API was available; old
application data was explicitly disposable.

## Root cause containment and cleanup

- Disabled/stopped old Timer; 15-second Workerman log growth check: delta 0.
- Preserved error journal sample proving the PHP 8.3 float-to-int crash loop.
- Truncated the 20.1 GB Workerman log only after stop.
- Stopped rsyslog, removed 12 GB + 20 GB syslog files, restarted writer.
- Vacuumed journal from 4.0 GB to about 440–448 MB.
- Disk changed from 95% used (3.8 GB free) to 13% used (58 GB free).
- B is on an independent HTTPS 503 maintenance page; static filing site and
  ACME path remain available.
- Dropped approved old `yoshop2` DB and localhost application users; deleted
  `/opt/yoshop`, old backups/dev tooling, obsolete stopped static container and
  duplicate static-page directory. Docker engine/image remain.

## Foundation

- Created `deployer` with temporarily reused current public key.
- Verified root and deployer key login after UFW/sshd reload.
- Scoped sudo test: release status allowed; arbitrary `/usr/bin/id` denied.
- Created `/srv/yoshop/{incoming,releases,state,shared}` ownership boundaries.
- Installed root-owned `/usr/local/sbin/yoshop-release`.
- Preserved old production env and payment material under protected `shared/`;
  payment directories/files are root:www-data 0750/0640.
- Added 2 GiB swap.
- Enabled UFW default deny with only 22/80/443 (IPv4/IPv6).
- Enabled Fail2ban sshd jail.
- Bounded journald to 512 MB/14 days and added release-log rotation.
- Installed Timer as www-data with 768 MB/128-task limits, no-new-privileges,
  protected filesystem and `StartLimitBurst=5` per 5 minutes; it remains
  intentionally disabled until verified code/data exists.
- Removed `f`; retained locked vendor-named `lighthouse` account but removed its
  unrestricted NOPASSWD sudo.
- Purged ModemManager, udisks2, upower, PackageKit, fwupd and their now-unused
  desktop/device libraries after simulation. `ubuntu-server` meta package and
  software-properties helper were removed only as dependency metadata/tools,
  not runtime services.

## Retained/verified

Active: Nginx, PHP 8.3 FPM, MySQL 8, Redis, Docker, containerd, Certbot timer,
Komari, Fail2ban, Tencent TAT/YunJing/Stargate/Barad agents. MySQL/Redis remain
loopback. Public TCP listeners are only 22/80/443. Maintenance returns 503 and
filing static page returns 200. Remote release status reports no active release.

Local release compatibility was also corrected so Composer applies the exact
Workerman `Select` integer-timeout patch that stopped the PHP 8.3 crash; a clean
upstream fixture patch/idempotency test passes. The WSL Timer has run stably for
18+ hours with that patch and no float crash.
