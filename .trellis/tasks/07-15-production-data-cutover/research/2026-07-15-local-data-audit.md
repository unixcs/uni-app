# Local baseline audit — 2026-07-15

## First-principles boundary

- Code releases must not contain environment files, payment keys, uploaded files, database dumps, or generated credentials.
- The initial data package is a one-time bootstrap: current schema plus an explicit safe-data whitelist. It is not a recurring deployment input.
- After cutover, Tencent MySQL and `/srv/yoshop/shared` become authoritative for production data/configuration. Routine releases may only change immutable code releases and forward migrations.
- Unknown payment configuration must fail closed rather than silently using the WSL sandbox in production.

## Source database inventory

The local database contains 64 current tables. Relevant counts observed before sanitation:

- goods: 11
- categories: 2
- goods images: 11
- goods SKUs: 11
- page records: 1
- delivery templates/rules: 1/1
- help records: 1
- uploads in DB / physical upload files: 16 / 28
- users / OAuth rows: 9 / 8
- orders / order goods: 87 / 87
- refunds / payment trades / iOS inquiries: 45 / 150 / 6
- balance logs / feedback: 326 / 7

The transactional/user rows above are explicitly forbidden in the production bootstrap.

## Referenced upload audit

Direct safe-business relations reference upload IDs `10002`, `10004`, and `10005`. After JSON-decoding `yoshop_page.page_data`, the page also references the path ending in `c3d13c1a3ce041b4449db382523e1f2d.jpg`, which maps to upload ID `10006`. This proves raw scanning of escaped page JSON is insufficient.

Expected initial referenced upload set from the current baseline: `10002,10004,10005,10006`. The generator/validator must derive this set rather than hard-code it, reject traversal, and verify every manifest checksum against a physical file.

## Configuration classification

Public/safe business settings:

- store identity, catalog, SKU, category, delivery, help, page decoration
- non-secret customer and registration behavior
- current static permission/menu/API tables needed by the admin/store applications

Private bootstrap data (never Git):

- wxapp basic setting (AppID/AppSecret present)
- SMS setting (QCloud credentials present)
- payment method/template rows (merchant key material present)
- virtual-payment setting
- newly generated DB/admin/store credentials

The local virtual-payment setting is a development sandbox configuration: it references domain A, has a sandbox key, and has no production app key or message token. Production B must therefore start with virtual payment disabled (`VIRTUAL_PAYMENT.ENABLED=0`, production environment selected, B notify base) until complete production credentials are supplied and separately validated. It must not run the sandbox configuration on B.

The three local WeChat payment PEM files and the three preserved server PEM files have identical SHA-256 contents even though their filenames differ. For the one-time bootstrap, using the local private payment configuration requires copying the matching local filenames into protected server shared storage; subsequent releases must never touch them.

## PHP 8.3 / Timer release validation

A fresh isolated backend tree was created without `vendor`, then `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` was run. The Composer hook applied `scripts/php83-vendor-compat.php` to fresh Workerman 3.5.34. The resulting `vendor/workerman/workerman/Events/Select.php`:

- passes `php -l`;
- contains `normalizeTimeout()`;
- normalizes both `stream_select()` and `usleep()` timeout values;
- passes a second idempotent compatibility-script run.

Scratch evidence is root-only under `/root/yoshop-workerman-package-check-iSlz3Irn`. A final real release package must be rebuilt from the clean, pushed commit and rechecked before production authorization.

## Generated and cross-version validated bootstrap artifacts

A final public initializer candidate was generated outside Git at the root-only path recorded in `/root/yoshop-cutover-private/public-package-path`. Local disposable-DB validation passed with:

- 64 current schema tables;
- 11 goods and one valid page JSON row;
- exact referenced upload IDs `10002,10004,10005,10006`;
- exactly `customer,register` public store-setting keys;
- 36 forbidden user/transaction/private tables empty;
- 9 final checksum entries;
- no remaining local validation database.

The same public schema/data package was transferred to the Tencent host's root-only validation area and restored into a random disposable MySQL 8.0.46 database. Checksums, schema import, data import, counts, page JSON, exact upload IDs, forbidden rows, and cleanup all passed; the temporary database was dropped.

A separate root-only private bootstrap was built outside Git. It contains only SMS, WxApp, payment/template configuration, newly generated admin/store password hashes, and matching payment files. The virtual-payment row was sanitized *before transfer*: disabled, production environment selected, notify base changed to B, and sandbox/production app keys plus message tokens blanked. This avoids writing the WSL sandbox secret/domain into Tencent MySQL or its binlog.

The combined public + private + credential-hash bootstrap was restored into a second disposable Tencent MySQL 8 database. Validation passed with:

- 11 goods;
- one new super-admin and one new super store user;
- one WxApp setting, nine payment method rows, and one payment template;
- four approved setting rows (`customer`, `register`, `sms`, `virtual_payment`);
- zero user/OAuth/order/refund/trade/feedback rows;
- virtual payment disabled on B with secret fields blank;
- all three payment-template certificate basenames matched protected staged files;
- no A-domain marker;
- temporary database removed after validation.

Plaintext first-login and DB credentials remain only in `/root/yoshop-cutover-private/` with mode `0600`; they have not been uploaded or imported. Production database creation/import remains behind the explicit production authorization gate.

## Prepared server tooling (not an application release)

The reviewed candidate-aware `/usr/local/sbin/yoshop-release` was installed on Tencent and its `status` command succeeds through the restricted `deployer` sudo rule. Inactive production and maintenance Nginx configs were installed under `sites-available`; the currently enabled site remains the 503 maintenance configuration. The inactive production config passes an isolated `nginx -t` syntax check. No candidate/current release exists, Timer remains disabled, and B has not been cut over.
