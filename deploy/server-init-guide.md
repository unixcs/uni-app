# Tencent Ubuntu 22.04 one-time initialization

This guide is for rebuilding the host foundation, not for routine releases.
Production never runs Git, Composer, or Node package downloads.

## Foundation

1. Keep the filing page, Docker/containerd, Certbot, Komari, Tencent agents, SSH,
   Nginx, PHP 8.3, MySQL 8, and Redis.
2. Run the reviewed `deploy/scripts/bootstrap-ubuntu22.04-production.sh` as root.
   It creates restricted `deployer`, `/srv/yoshop`, security controls, log
   bounds, swap, and the disabled Timer unit.
3. Install the production and maintenance Nginx fragments from `deploy/nginx/`.
   Keep maintenance enabled until the first candidate, DB, shared data, and
   rollback path have all been verified.
4. Install `deploy/mysql/60-yoshop-retention.cnf`, run
   `mysqld --validate-config`, and use seven-day row-binlog retention.

## Protected production state

Create these only through the authorized cutover procedure:

```text
/srv/yoshop/shared/.env
/srv/yoshop/shared/mysql-client.cnf
/srv/yoshop/shared/db-name
/srv/yoshop/shared/uploads/
/srv/yoshop/shared/payment/
/srv/yoshop/shared/runtime/
/srv/yoshop/shared/backups/
```

`.env` and payment/DB credentials are never copied by a routine release. The
one-time sanitized initializer is restored to a temporary DB and validated
before the production DB is created.

## First release

1. Commit and push a clean `main` only after the Git authorization gate.
2. Build locally; inspect release ID, manifest, SHA-256, domain and secret scans.
3. Prepare the immutable candidate with the independent PREPARE token while B
   still returns maintenance 503.
4. Validate DB/shared paths and a tested maintenance-vhost rollback command.
5. At the production authorization gate, switch the reviewed business vhost and
   explicitly activate the prepared ID. Restore maintenance immediately if
   activation or smoke checks fail.
6. Enable Timer only through successful activation; then observe restart count,
   logs, memory, and disk.

## Routine releases

Use only the documented `./deploy.sh release --fetch` flow in `deploy/README.md`.
It builds locally, uses `deployer`, keeps shared production state untouched,
and atomically switches immutable releases.

## Certificate check

Both `wx.gxwqb.cn` and `www.gxwqb.cn` must serve
`/.well-known/acme-challenge/` from `/var/www/html`. Validate with:

```bash
sudo deploy/scripts/renew-acme-cert.sh --dry-run --no-random-sleep-on-renew
```
