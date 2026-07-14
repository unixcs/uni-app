# Deployment tooling validation evidence

## Implemented contract

- `deploy.sh` -> Python standard-library controller (`preflight`, `build`,
  `deploy`, `release`, `status`, `rollback`).
- Clean/pushed-main gate plus generated/private tracking policy.
- Local admin/store/H5/Composer build, production-domain and secret scans,
  per-file manifest, deterministic gzip/tar metadata, package SHA-256.
- rsync to `deployer`, fixed root-owned remote executor, immutable releases,
  shared data links, checksummed migrations, atomic current symlink, local/public
  health checks, failed-health code rollback and retention.
- Test/prod mini-program commands with awaited config restoration, Windows
  mirror sentinel, dangerous-path guard, and compiled-domain verification.
- Project `$yoshop-deploy` Skill with explicit Git and production gates.

## Automated validation

- Python deploy tests: 6/6 pass (tracking, URL restoration, secret/domain scans,
  reproducible tar, production authorization).
- Remote release integration: two installs, rollback, shared symlinks and
  simulated failed-health automatic rollback pass.
- ShellCheck and `bash -n`: pass for new shell entry points/tests.
- Mini-program guard regression: pass.
- Test HBuilderX build: source/mirror restored to `wx.oiob.cn`; artifact test URL.
- Production HBuilderX build: source/mirror restored to `wx.oiob.cn`; artifact
  contains `wx.gxwqb.cn` and not A.
- Skill `quick_validate.py`: pass.
- Python/PHP syntax, JSON validation, `git diff --check`: pass.
- CLI stdout machine-readable JSON: pass.

## Full isolated release rehearsal

A fresh isolated clean Git snapshot of all intended current files was created
outside the working tree. Local dependency directories were attached only for
the build and remained untracked. Full `deploy.sh build` passed:

- admin production build: pass (legacy size warnings only)
- store production build: pass (legacy CSS order warnings only)
- H5 production build: pass (upstream Sass deprecations only)
- Composer `--no-dev --optimize-autoloader`: pass; PHP 8.3 patches applied
- release secret/domain scans: pass after distinguishing actual PEM blocks from
  SDK source-code marker literals
- package: 18,351,510 bytes, 6,382 manifested files
- SHA-256 sidecar: verified
- required vendor/H5/admin/store entries: present
- `.env`, upload contents, payment data, runtime: absent
- H5 config: domain B present; domain A absent

The live repository intentionally still fails preflight because commit/push and
history cleanup are the explicit user authorization gate. No production rsync
or SSH mutation has occurred.

## Final deterministic-build and live-release validation

The earlier 6/6 test count and statement that no production mutation had
occurred describe an intermediate pre-authorization checkpoint, not the final
acceptance state. The final deploy Python suite passed 37/37, and the
production initializer suite passed 12/12. The authorized live release/rollback
exercises below completed on 2026-07-15.

### Deterministic package result

During final validation, nondeterminism was traced to and corrected in five
places: nested Composer VCS metadata, ThinkPHP's wall-clock-generated services
file, a randomly named Webpack app CSS asset, Composer autoload ordering, and a
root Composer interactive prompt. Two complete builds of the same commit then
produced exactly the same outputs:

- package SHA-256:
  `73994b08593b16705169ed81dac51e9eff87f19596e588153c998af076532081`;
- manifest SHA-256:
  `205fa97db68f8a1344ebd2c88cdeb60d2af3a81998065ee8e7f883a973244f85`;
- no nested VCS metadata in the package;
- the content-addressed admin and store CSS assets both returned HTTP 200 from
  production.

### Live release and rollback result

- A real one-command production release succeeded. Final `status` was `ok` with
  current release `20260714215523-b777b892d048`, previous release
  `20260714210305-c8ac8179f5c8`, three release directories present, and 13%
  disk use.
- Production smoke checks passed 10/10 after release.
- A real code rollback to the previous release passed smoke checks 10/10; the
  latest release was then restored and again became current.
- The independent shared-state snapshot was unchanged through release,
  rollback, and restoration. Its SHA-256 was
  `11a88da94d69be1fd18a308a57beb1a2e582027feecf69ecb2b5b49ad7d59b7f`.
- The permission regression was corrected and rechecked: `/srv/yoshop` root is
  0711, incoming is `deployer:www-data` 0750, backups are `root:root` 0700, and
  the deployer boundary test passed.

The application Timer passed the final stability gate on the final release:
2,075 seconds observed at the start of a final 60-second sample, enabled and
active throughout, `NRestarts=0` before and after, zero warning-or-higher
journal entries, zero Timer-log growth, and PID state present only under the
shared runtime path.
