# Repository cleanup execution evidence

## Recovery baseline

The parent migration audit created and verified the recovery set at:

- root-only: `/root/yoshop-migration-backups/20260715-015540`
- non-secret Windows copy: `/mnt/d/Program/0/home/0/yoshop-backups/20260715-015540`

The full Git bundle, HEAD archive, binary staged/unstaged diffs, untracked archive,
private runtime backup, DB dump, and SHA-256 manifests passed restore checks.
Database restore probes: 64 tables, 11 goods, 87 orders.

## Workspace cleanup

`scripts/cleanup-local.sh --apply` removed only regenerable outputs and caches:
root/runtime, root/tmp, backend runtime, three frontend dist/unpackage outputs,
Vite metadata, and Vue build caches. It preserved all dependency directories,
PHP vendor, `.env`, uploads, payment data, and the current public build used by
local Nginx. The default command is dry-run; dependency deletion requires both
`--apply --deep` and still never removes PHP vendor/private data.

## Git tracking

The precise root `.gitignore` was verified with `git check-ignore --no-index`.
The index cleanup stages removal of 947 generated/upload files and addition of
the uploads sentinel. `git ls-files -ci --exclude-standard` returns zero paths.
No commit or push has been made; those remain an explicit user gate.

## Build and test evidence before cleanup

- PHP 8.3 lint: all 38 changed/untracked PHP files passed.
- Backend contract scripts passed except the two mutating fixture suites, which
  correctly refused without their explicit opt-in environment variables.
- Local services `nginx`, `php8.3-fpm`, `mysql`, and `redis-server` were active;
  `http://127.0.0.1/` returned HTTP 200.
- Admin production build passed (size warnings only).
- Store production build passed (legacy CSS-order warnings only).
- Store unit tests: 4 suites / 30 tests passed.
- H5 production build passed (upstream Sass deprecation warnings only).
- Windows HBuilderX mini-program build passed and refreshed required artifacts.

## Isolated history rewrite rehearsal

`git-filter-repo` 2.38.0 was installed from the Ubuntu package repository.
`scripts/rewrite-git-history.sh` was safety-tested and then run end-to-end on a
fresh single-branch bare clone. Results:

- original main: 34 commits, 17,628,044 bytes bare clone
- rewritten main: 33 commits, 7,009,624 bytes
- removed path matches across all rewritten history: 0
- `git fsck --full --strict`: PASS
- valid backend/store/uniapp source probe: 1,036 paths
- the only pruned commit was `chore: 部署最新商家后台静态资源`, which became
  empty because it contained generated merchant-console output only

The tested script creates and verifies a pre-filter bundle and refuses the live
`/opt/yoshop/.git`. Final rewrite and `--force-with-lease` push have not run;
they require the commit/push authorization gate.

## Upload sentinel rewrite regression — corrected

A final review found that the first rehearsal used a broad inverted filter for
`yoshop2.0/public/uploads/`. That correctly removed private uploads but would
also remove the newly added `.gitignore` sentinel once the pending cleanup was
committed. The rewrite script now uses a byte-path filename callback: all
historical upload entries are removed except the exact sentinel. Its verifier
separately rejects any other upload path and requires the sentinel in `main`.

A fresh isolated 35-commit fixture (current history plus a sentinel commit) was
rewritten end-to-end. Results:

- old bare repository: 29,853,570 bytes / 35 commits;
- rewritten bare repository: 7,010,584 bytes / 34 commits;
- `git fsck --full --strict`: passed;
- historical upload paths after filtering: exactly
  `yoshop2.0/public/uploads/.gitignore`;
- all other generated directory probes: zero;
- script `bash -n`, ShellCheck and diff whitespace checks: passed.

The fixture and its generated backup bundle were temporary and removed after
verification. The real repository and GitHub remote were not rewritten or
pushed; those operations remain behind the explicit Git authorization gate.

## Raw Trellis evidence boundary

A pre-commit candidate scan found that one untracked machine-generated JSON
under `research/evidence/` contained a test user's mobile number and concrete
trade identifiers. These files are useful locally for diagnosis but are not
business source and must not be copied to GitHub. The root `.gitignore` now
keeps `research/evidence/*.json` and `research/pre-edit/` snapshots local while
sanitized Markdown conclusions, PRDs, designs, tests and specs remain eligible
for version control. Both rules were verified with `git check-ignore`; the
local evidence itself was preserved and not printed, deleted or modified.

## Final pre-authorization development-chain rerun

After the repository and deployment tooling reviews, the complete non-mutating
development chain was rerun from the preserved dependency directories:

- all 32 changed/untracked PHP files linted successfully;
- six non-mutating backend contract suites passed (payment channel, immediate
  refund convergence, iOS refund review/lock, non-iOS regression, Timer runtime,
  and repeated-cashier guard);
- admin production build passed (only existing bundle-size/dependency warnings);
- store production build passed (only existing CSS-order/dependency warnings);
- store focused ESLint for every changed/new source and unit-test path passed;
- store unit tests passed: 4 suites / 30 tests;
- H5 production build passed with upstream Sass deprecation warnings;
- all three mini-program Node contract suites passed;
- guarded Windows HBuilderX test build passed and restored both temporary API
  config files, producing the Windows `mp-weixin` artifact for A-domain testing;
- local Nginx, PHP 8.3 FPM, MySQL and Redis remained active; local HTTP returned
  200;
- `git ls-files -ci --exclude-standard` remained zero and `git diff --check`
  passed.

The full legacy admin/store repository-wide lint commands still report
pre-existing style debt in many untouched files (admin: 25 errors; store:
many legacy rule violations). This is not hidden as a green check. Current
changed store paths pass focused ESLint, both applications compile, and no
unrelated mass auto-format was applied because that would violate the scoped
cleanup and increase regression risk.

## Final history-rewrite execution

The earlier statements that no commit, push, or live rewrite had occurred are
preserved above as evidence of the pre-authorization phase. They were
superseded after the explicit execution gate was granted on 2026-07-15:

- `main` at the original GitHub remote (`git@github.com:unixcs/uni-app.git`) was
  rewritten and updated with `--force-with-lease`.
- Historical generated outputs, non-sentinel uploads, and committed key
  material were absent from the rewritten full history; strict `git fsck`
  passed.
- At the final execution checkpoint, the working tree was clean and `.git` was
  approximately 7.5 MiB.
- The previously exposed WxApp AppSecret was redacted from history, but history
  removal does not revoke a credential. Manual WxApp AppSecret rotation remains
  mandatory and deferred; no secret value is recorded in this evidence.
