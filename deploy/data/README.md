# Sanitized production initializer package

`initializer.py` implements the bounded, local-only part of the production data
cutover. It reads the source MySQL database, creates a full current schema dump,
exports only policy-allowlisted static/business rows, selects only uploads
referenced by those safe rows, and validates the package by restoring it into a
random temporary database.

It never connects to Tencent and never modifies the source database.

## Security boundaries

- Supply connection details explicitly with CLI/environment variables, or use a
  MySQL `[client]` defaults file whose mode is `0600`. A password is read from
  `--password-env` (default `YOSHOP_DB_PASSWORD`) and is never placed in argv,
  reports, or normal output.
- Generated packages must be outside the Git worktree and the output path must
  not already exist. The package root is `0700` and generated files are `0600`
  by default, even though public artifacts are sanitized.
- The public `data.sql` excludes user, order, refund, payment, feedback, OAuth,
  admin/store-user, WxApp/H5 setting, and private setting data. Only the
  audited non-secret `store_setting.customer` and `store_setting.register` rows
  are allowlisted.
- This bounded tool deliberately does **not** export private configuration. If a
  later operation needs private settings, it must be a separate explicit
  opt-in artifact outside Git with mode `0600`; do not add it to this public
  package.
- `policy.json` is intentionally auditable. Every prefixed source table must be
  classified as safe, required-empty, or the specially selected `upload_file`;
  unknown tables fail closed until the policy is reviewed after a schema change.

## Generate

Create a restricted defaults file outside Git, or provide host/user and a
password environment variable. Do not paste secret values into shell history.

```bash
python3 deploy/data/initializer.py generate \
  --mysql-defaults-file /run/user/$UID/yoshop-local.cnf \
  --database yoshop_pro2 \
  --uploads-root yoshop2.0/public/uploads \
  --output /tmp/yoshop-production-initializer \
  --expected-goods-count 11
```

Equivalent explicit non-secret connection parameters are `--host`, `--port`,
`--user`, and `--database`; the password remains in `YOSHOP_DB_PASSWORD` (or the
variable named by `--password-env`). Add `--secret-env NAME` for each other
known secret value that must be rejected if it appears in a public artifact.

Generation produces:

- `schema.sql` — current full schema from `mysqldump --no-data`, including
  triggers/routines/events;
- `data.sql` — allowlisted rows plus only selected `upload_file` rows;
- `uploads/` and `uploads-manifest.json` — referenced local files, actual sizes,
  source-DB size metadata/mismatch flags, SHA-256 hashes, and safe-source
  provenance;
- `generation-report.json` — non-secret counts and policy hash;
- `SHA256SUMS` — exact coverage of every public package file except itself.

Page data is HTML-unescaped and JSON-decoded before recursively extracting
upload paths, so JSON `\/uploads\/...` references are supported. Paths are
URL-decoded, checked for traversal/empty segments, resolved below the supplied
uploads root, and required to match an `upload_file` row. Upload IDs are drawn
only from safe direct relations and safe content; user/order/refund/feedback-only
files are never selected.

## Validate in an isolated temporary DB

The MySQL account must be allowed to create and drop a temporary database. The
validator creates only a random database with the configured prefix, imports
`schema.sql` and `data.sql`, validates it, and drops it in a `finally` cleanup.
It never selects or writes the source database.

```bash
python3 deploy/data/initializer.py validate \
  --mysql-defaults-file /run/user/$UID/yoshop-local.cnf \
  --database yoshop_pro2 \
  --package /tmp/yoshop-production-initializer \
  --expected-goods-count 11
```

Validation checks exact checksum coverage, manifest/file size and hashes,
private-table INSERT exclusion, forbidden development domains, private-key and
secret-like material, exact configured secret values, empty transactional/user
rows after restore, expected goods count, restored page JSON, allowlisted store
setting keys, and exact `upload_file`/manifest agreement. On success it writes
`validation-report.json` and atomically refreshes `SHA256SUMS` to include the
report.

## Tests

```bash
python3 -m unittest discover -s deploy/data/tests -v
python3 -m py_compile deploy/data/initializer.py deploy/data/tests/test_initializer.py
```

The automated tests use temporary fixture directories and in-memory records;
they do not connect to or mutate the real local database.

## Private bootstrap handoff / future extension contract

Private bootstrap is deliberately a separate future implementation step, not a
mode of this public generator. Any extension must preserve all of these
boundaries:

1. Use a separate executable and a separate, explicit opt-in flag; it must not
   run transitively from `generate` or `validate`.
2. Require an output path outside every Git worktree, set `umask 077`, create a
   root-owned `0700` directory, and write secret-bearing files as `0600`. Never
   place private files below the public package, include them in public
   `SHA256SUMS`, or print their contents/secret-derived values.
3. Keep an explicit private allowlist limited to production-provided WxApp,
   SMS, payment/payment-template, and virtual-payment configuration. Do not
   export users, OAuth, orders, refunds, feedback, payment trades, local DB
   credentials, admin/store passwords, or unrelated settings.
4. Treat local sandbox/A virtual-payment configuration as invalid production
   input. The private bootstrap must start fail-closed: virtual payment disabled,
   production environment selected, B notify base configured, and no sandbox
   key copied. Enabling it requires all production key/token fields to be
   separately supplied and validated; missing or ambiguous fields are fatal and
   must never be guessed.
5. Payment PEM files remain separate protected shared-storage files. A private
   report may contain only filenames, modes, and SHA-256 checksums—not PEM
   content—and must verify the application configuration refers to the deployed
   filenames before activation.
6. Validate the private artifact independently against a disposable database or
   staging configuration, then transfer it through a root-only channel into
   production shared storage. Destroy transient local copies after a recorded
   checksum handoff. Routine code releases must never read, package, overwrite,
   or re-import it.

The eventual private artifact should have its own private checksum/report set;
only a non-secret handoff receipt (artifact ID, filenames, checksum labels,
validation status, and timestamp) may be copied into operational evidence.
