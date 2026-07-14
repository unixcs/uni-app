# Public initializer generator/validator evidence — 2026-07-15

## Scope and schema inspection

- Implementation is confined to `deploy/data/**`; this evidence file is the only
  other path written.
- Read the live local schema before implementation through a temporary mode-0600
  MySQL client file. No credential values were printed or persisted in Git.
- Confirmed 64 tables and the current safe relations/content columns, including
  direct upload IDs from article/category/goods/goods_image/goods_sku/store and
  JSON-decoded `page.page_data` content paths.
- Public data policy retains catalog/SKU/category/service/spec, page/help/article,
  delivery/region/express, store identity/address, static API/menu/role data,
  upload groups, and audited non-secret `store_setting` keys `customer` and
  `register`.
- Public data policy excludes users/OAuth/grades, admin/store users, carts,
  comments/coupons, orders/refunds, payment/trades/templates/inquiries, recharge,
  feedback/balance logs, process rows, H5/WxApp settings, SMS, and virtual-payment
  settings. Private bootstrap remains a separate future root-only artifact; the
  handoff/extension contract is documented in `deploy/data/README.md`.

## Automated checks

Commands:

```bash
python3 -m py_compile deploy/data/initializer.py deploy/data/tests/test_initializer.py
python3 -m unittest discover -s deploy/data/tests -v
python3 -m compileall -q deploy/data
git diff --check -- deploy/data
```

Result: 11 unit/fixture tests passed. Coverage includes decoding page JSON before
path extraction, literal escaped `\/uploads\/` paths, rich-text/self-closing
image paths, invalid JSON, plain/double-percent-encoded traversal rejection,
safe direct+content upload union, exclusion of unsafe-only fixture uploads,
missing/deleted/remote upload rejection, exact checksum coverage/tamper detection,
forbidden-domain/private-key/exact-secret scanning, forbidden transactional SQL,
and manifest traversal rejection. Tests use only temporary files and in-memory
records; they never connect to the local database.

The Git-output guard was also exercised before any DB connection:

```bash
python3 deploy/data/initializer.py generate \
  --database placeholder \
  --uploads-root yoshop2.0/public/uploads \
  --output deploy/data/out
```

Result: exit 2 with the expected outside-Git refusal.

## Live read-only generation and isolated restore validation

Connection material was loaded into ephemeral mode-0600 client files. Generation
used the ordinary local application account read-only; validation used local
root Unix-socket authentication because the application account correctly lacks
`CREATE DATABASE` permission. No source DB writes occurred.

Principal commands (shell variables pointed only to ephemeral restricted files
and the explicitly read local DB name):

```bash
python3 deploy/data/initializer.py generate \
  --mysql-defaults-file "$app_cnf" \
  --database "$database" \
  --uploads-root yoshop2.0/public/uploads \
  --output /tmp/yoshop-production-initializer-test-4 \
  --expected-goods-count 11

python3 deploy/data/initializer.py validate \
  --mysql-defaults-file "$root_cnf" \
  --database "$database" \
  --package /tmp/yoshop-production-initializer-test-4 \
  --expected-goods-count 11
```

Results:

- generation passed: 11 goods, 1 decoded page JSON row, 4 derived uploads;
- derived manifest IDs were `10002,10004,10005,10006` (derived from live safe
  relations/path mapping, never hard-coded in implementation or fixtures);
- isolated temporary-DB restore passed and cleanup dropped the random validation
  database;
- 36 forbidden/private/transactional tables restored empty;
- restored settings were exactly `customer` and `register`;
- merchant `store_address` restored with 1 row;
- restored `upload_file` IDs exactly matched the 4 manifest entries and every
  referenced physical file existed with an actual SHA-256;
- `SHA256SUMS` covered all 9 final package files, including the successful
  validation report;
- development-domain, secret-material, private-table INSERT, page-JSON, expected
  goods-count, manifest/file, and checksum checks passed;
- output permissions were root directory `0700`, files `0600`;
- 2 source DB `file_size` metadata values differed from actual file bytes. The
  package records both values plus mismatch flags and validates actual bytes and
  SHA-256; stale source metadata is preserved rather than silently rewritten.

An initial validation attempt with the application account failed safely at
`CREATE DATABASE` with no source mutation. This confirms the documented
assumption that restore validation needs a separate local account permitted to
create/drop disposable databases. No Tencent connection, commit, push, deploy,
or private-config export was performed.

## Final fail-closed policy rerun

After the evidence above, the policy gate was tightened so every one of the 64
prefixed schema tables must be classified (safe, required-empty, or the special
referenced-upload table), and public SQL INSERT targets must be a subset of the
safe allowlist. Unknown future tables and non-allowlisted mutation statements
now fail closed. The final fixture count is 12 tests (all passed).

The same generation/restore commands were rerun against
`/tmp/yoshop-production-initializer-test-5`. Final integration assertions passed:
64 schema tables classified, 36 forbidden tables empty after restore, 11 goods,
1 valid page JSON row, 4 derived referenced uploads, and 9 final checksum
entries. Output permissions remained `0700`/`0600`.
