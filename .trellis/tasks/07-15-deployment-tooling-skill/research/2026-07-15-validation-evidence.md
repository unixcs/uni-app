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
