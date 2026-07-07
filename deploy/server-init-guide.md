# Tencent Cloud Ubuntu 22.04 server init guide

This guide is the minimum order for OpenSpec task 4.1. It stops at platform
bootstrap and does not deploy the full application code or frontend bundles.

## Recommended order

1. **Package install / base bootstrap**
   - Run `deploy/scripts/bootstrap-ubuntu22.04-production.sh` as root.
   - Confirm nginx, mysql-server, redis-server, `php8.3-fpm`, and `composer` are available.

2. **Place the application env file**
   - Copy `deploy/env/yoshop2.0.env.example` to `/opt/yoshop/yoshop2.0/.env`.
   - Fill in the production host, database, and Redis values for `wx.gxwqb.cn`.
   - Keep debug disabled and do not store secrets in the repo.

3. **Create the database and import schema/data**
   - Run `mysql_secure_installation` first.
   - Create a dedicated database and application user.
   - Import `deploy/sql/install.sql`.
   - Import `deploy/sql/demo-content.sql` or other patch SQL only if required.

4. **Install backend PHP dependencies**
   - Run `cd /opt/yoshop/yoshop2.0 && composer install`.
   - Do not pass `--no-scripts`, otherwise the PHP 8.3 compatibility patches will not run.

5. **Enable Nginx site**
   - Copy `deploy/nginx/wx.gxwqb.cn.conf` to `/etc/nginx/sites-available/`.
   - Link it into `/etc/nginx/sites-enabled/`.
   - Validate with `nginx -t`, then reload nginx.

6. **Fix permissions**
   - Ensure `/opt/yoshop/yoshop2.0/public`, `/public/admin`, and `/public/store` exist.
   - Ensure `/opt/yoshop/yoshop2.0/public/uploads` and `/opt/yoshop/yoshop2.0/runtime` are writable by the PHP-FPM user.
   - Recommended owner/group: `www-data:www-data`.
   - Keep the web user able to read the site tree.
   - Recheck ownership after any future code upload.

7. **Enable the timer supervisor**
   - Copy `deploy/systemd/yoshop2.0-timer.service` into `/etc/systemd/system/`.
   - Run `systemctl daemon-reload`.
   - Enable and start `yoshop2.0-timer.service` so `php think timer start` is supervised by systemd and logs to journald.

8. **Check services**
   - Verify `systemctl status php8.3-fpm nginx mysql redis-server`.
   - Verify `systemctl status yoshop2.0-timer.service`.
   - Confirm PHP-FPM socket path is `/run/php/php8.3-fpm.sock`.
   - Visit `https://wx.gxwqb.cn/` only after DNS and TLS are ready.

9. **Prepare ops support basics**
   - Create a backup location such as `/opt/yoshop/backups`.
   - Keep `deploy/scripts/backup-mysql.sh` and `deploy/scripts/rollback-mysql.sh` available for manual ops.
   - Recommended production DB name in current deployment: `yoshop2`.
   - Validate ACME renewal with `deploy/scripts/renew-acme-cert.sh` if the environment uses certbot.

## Manual security reminders

- Replace all placeholders in `.env` before first launch.
- Use a real database password and a least-privilege MySQL account.
- Restrict Redis to localhost unless a private network and authentication are in place.
- Add TLS/ACME and firewall rules before exposing the site publicly.
- Keep a tested database backup and rollback path before each production change.
