# YoShop release contract

## Fixed ownership boundaries

- Git/release: application source, locally built PHP `vendor`, H5, admin, store.
- `/srv/yoshop/shared/.env`: production configuration.
- `/srv/yoshop/shared/uploads`: user and retained seed media.
- `/srv/yoshop/shared/payment`: payment certificates/private keys.
- `/srv/yoshop/shared/runtime`: writable application runtime.
- `/srv/yoshop/shared/backups`: pre-migration MySQL backups.
- `/srv/yoshop/current`: atomic symlink to an immutable release.

The remote release command replaces release code only and recreates links to
shared data. Local files cannot overwrite these shared paths.

The executor must reassert the production directory boundary on every command:
`/srv/yoshop` 0711, `incoming` deployer:www-data 0750, `releases`/`state`
root:www-data 0750, `shared/backups` root:root 0700, and shared writable
uploads/runtime www-data:www-data 0770. This prevents status/install from
silently reopening backups or making `incoming` unreachable to deployer.

## Expected checks

- `preflight`: main, clean tree/index, HEAD equals refreshed origin/main, no
  generated/private path tracked.
- `build`: local Composer/frontend builds, secret scan, A/B domain scan,
  per-file manifest, reproducible archive, package SHA-256. Two builds of the
  same pushed commit must have identical package and manifest SHA-256 values;
  a matching release ID alone is insufficient.
- `install`: archive traversal defense, checksum/manifest/entry-point checks,
  shared links, one-time checksum-pinned SQL migrations, atomic symlink, service
  restart, local and public HTTPS health checks, automatic code rollback.

## Post-release smoke

1. `./deploy.sh status` reports the new release ID.
2. `https://wx.gxwqb.cn/` returns H5 and its `config.js` uses domain B.
3. `/admin/` and `/store/` return their built index pages.
4. A public read-only API endpoint responds through PHP.
5. Nginx, PHP-FPM, MySQL, Redis, and timer service are healthy.
6. Upload directory and payment path resolve under `/srv/yoshop/shared`.
7. No repeating timer exception or rapid log growth appears.

## Rollback and roll-forward

Rollback swaps the recorded `current` and `previous` immutable releases. If the
operator approves returning to the release that was just rolled back, invoke
the guarded rollback command again; do not rebuild or reinstall the same
commit. A pushed commit has one deterministic release ID, and an existing
immutable release must reject duplicate installation. New deployment uses a
new commit and a new release ID. Database migrations are never reversed by
code rollback.

## Failure evidence

Capture release ID, report JSON, `journalctl -u yoshop2.0-timer.service`, Nginx
error log, PHP-FPM log, disk use, and the remote release log. Do not paste `.env`,
MySQL client config, access tokens, payment payloads, or private keys.
