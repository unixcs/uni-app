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

## Expected checks

- `preflight`: main, clean tree/index, HEAD equals refreshed origin/main, no
  generated/private path tracked.
- `build`: local Composer/frontend builds, secret scan, A/B domain scan,
  per-file manifest, reproducible archive, package SHA-256.
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

## Failure evidence

Capture release ID, report JSON, `journalctl -u yoshop2.0-timer.service`, Nginx
error log, PHP-FPM log, disk use, and the remote release log. Do not paste `.env`,
MySQL client config, access tokens, payment payloads, or private keys.
