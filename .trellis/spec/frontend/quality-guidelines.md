# Quality Guidelines

> Code quality and verification rules for the YoShop frontend apps.

---

## Overview

Quality in this repo is enforced primarily through:
- targeted lint/build commands;
- PHP syntax checks for cross-layer changes when needed;
- careful manual smoke testing for critical user flows;
- Trellis contract/spec updates when a bug reveals reusable knowledge.

There is little app-level automated test coverage, so build discipline matters.

## Forbidden Patterns

- **Line-ending-only churn**: do not carry CRLF/LF noise as a functional change.
- **Tracked or hand-edited generated artifacts**: `dist/`, `unpackage/`, and backend `public/{admin,store,assets}` outputs must stay ignored. Production bundles are created by `deploy/deploy.py` from clean, pushed source.
- **Cross-layer drift**: do not add UI fields, filters, exports, or status labels without checking every consumer and updating the matching contract doc.
- **Direct request sprawl**: do not bypass `api/*.js` wrappers from arbitrary components.
- **Speculative refactors during feature work**: keep the Vue 3 miniapp and Vue 2 merchant console patterns stable unless the task is explicitly architectural.
- **Unregistered Vue instance dependencies**: do not call `this.$foo` unless the app bootstrap or an installed plugin demonstrably registers `$foo`. Import ordinary libraries such as `moment` explicitly; an import used by filters does not create `this.$moment`.
- **Unsafe display dereferences**: do not render `Enum[value].name` or optional nested fields without a fallback when one unexpected value could abort the whole Vue render.

## Required Patterns

- Search for a field / menu path / config key before changing it.
- Keep request wrappers, page state, and UI components aligned with the existing module split.
- When adding or fixing a shared business contract, update the relevant Trellis spec in the same task.
- Separate deploy artifacts from source-code commits when both are needed.
- If a detail API rejects or returns no valid entity, render an explicit error state; do not replace the entity with `{}` and present an all-placeholder page that looks like missing business data.
- If a bug fix depends on tooling/runtime behavior, validate the real output, not just the source diff.
  - Example: after the mp-weixin build helper change, verify the compiled `static/tabbar/*.png` files exist in `unpackage/dist/dev/mp-weixin/static/tabbar/`.

## Validation Commands

### Merchant console (`yoshop2.0-store`)

Use WSL-friendly commands because `package.json` scripts are Windows-flavored:

```bash
cd /opt/yoshop/yoshop2.0-store
NODE_OPTIONS=--openssl-legacy-provider npx vue-cli-service lint <touched-files>
NODE_OPTIONS=--openssl-legacy-provider npx vue-cli-service build
```

### Miniapp (`yoshop2.0-uniapp`)

```bash
cd /opt/yoshop/yoshop2.0-uniapp
npm run build:mp-weixin:test  # wx.oiob.cn, development/experience only
npm run build:mp-weixin:prod  # wx.gxwqb.cn, production artifact only
```

The build must keep the WSL and Windows-mirror source `config.js` on domain A
after completion while the compiled artifact contains only the selected domain.
The guarded mirror sentinel is mandatory before any `rsync --delete`.
Use H5/manual verification only when the touched flow requires it.

### Cross-layer / backend-adjacent changes

When frontend work depends on PHP-side fields or commands, pair frontend checks with targeted backend syntax validation:

```bash
php -l <touched-php-file>
```

## Testing Expectations

- Favor targeted validation over blanket rebuilds, but do run a real build for the touched app before closing substantial work.
- For user-facing forms and platform-specific behavior, keep a manual smoke checklist in the task artifact or final report.
- For deployment changes, verify the release package contains the built store output; never stage backend `public/store` in Git.
- For render-time regressions, add a component/computed test that reproduces the triggering payload. A source grep alone is insufficient; the test must fail on the broken dependency or unsafe value and pass without globally mocking it.
- After a merchant deployment, verify the public HTTP bundle—not only local `dist`—contains the guard and no longer contains the broken expression.

## Code Review Checklist

Before finalizing a frontend task, confirm:
- request params and backend field names still match;
- route/menu/permission paths are aligned;
- list/detail/export views show the same contract fields;
- local-only noise (cache files, runtime files, line endings) is not mixed into commits;
- spec docs are updated if the task taught us a reusable rule.

## Merchant Console Deployment Contract

A successful `yoshop2.0-store/dist` build is **not** a production deployment.
Production Nginx serves `/srv/yoshop/current/yoshop2.0/public/store`; the guarded
release builder copies local `dist` into an immutable package and the remote
executor atomically switches `current`:

```bash
cd /opt/yoshop
./deploy.sh preflight --fetch
./deploy.sh release --fetch --dry-run
# after explicit production authorization:
./deploy.sh release --fetch --confirm-production DEPLOY-wx.gxwqb.cn
```

Required evidence: the package manifest contains `public/store/index.html` and
its hashed bundles, `/store/` returns that index after activation, and the actual
HTTP JS bundle contains the expected feature marker. Restarting PHP-FPM cannot
publish Vue assets. Do not manually rsync generated output into a mutable app
folder or commit it as a deployment artifact.

## Deterministic Release Package Contract

### 1. Scope / Trigger

This contract applies whenever `deploy/deploy.py`, Composer release assembly,
admin/store production output, manifest generation, or archive construction is
changed. It prevents the same pushed commit from producing a different package
because of wall-clock data, install order, nested repository state, or unstable
Webpack asset names.

### 2. Signatures

```bash
./deploy.sh preflight --fetch
./deploy.sh build --fetch
./deploy.sh release --fetch --dry-run
./deploy.sh release --fetch --confirm-production DEPLOY-wx.gxwqb.cn
```

The Composer stabilization step is fixed and non-interactive:

```bash
composer dump-autoload --working-dir <staged-backend> \
  --no-dev --optimize --no-scripts --no-interaction
```

### 3. Contracts

- A clean, pushed `main` commit built twice from the same locked dependencies
  must produce byte-identical `tar.gz` files and identical
  `release-manifest.json` files.
- The release must not contain any path component named `.git`, `.hg`, or
  `.svn`. A VCS marker that is a file or symlink is an error, not something to
  follow or silently package.
- ThinkPHP `vendor/services.php` may be normalized only when its known generated
  header matches; its time is replaced with the Git commit time in UTC. Unknown
  headers fail the build.
- Admin/store's single `css/app.<8hex>.css` entry is renamed from its unstable
  Webpack name to `css/app.<full-content-sha256>.css`. `index.html` must contain
  exactly one preload and one stylesheet reference to that entry. JS and other
  chunks are unchanged.
- Composer's second autoload dump runs after install but before scanning and
  manifest generation. `--no-scripts` must keep service discovery and PHP 8.3
  patch hooks from running twice; `--no-interaction` must prevent root prompts.
- Tar entries are sorted and use fixed uid/gid, owner names, commit mtime, and
  zero gzip mtime. Shared `.env`, uploads, payment files, runtime, and production
  data remain outside the archive.

### 4. Validation & Error Matrix

| Condition | Required behavior |
| --- | --- |
| No or multiple admin/store entry CSS files | Refuse the build |
| Entry CSS is a symlink, directory, or escapes the app root | Refuse without following it |
| HTML lacks either exact entry-CSS link or has extra references | Refuse the build |
| Content-addressed CSS target already exists | Refuse; do not overwrite |
| Index replacement fails after CSS rename | Restore the original CSS name and fail |
| Nested real VCS directory | Remove before scanning/manifest |
| VCS marker is a file/symlink or resolves outside stage | Refuse the build |
| ThinkPHP services header is unknown | Refuse without modifying the file |
| Composer asks for input | Treat as a command failure; never wait interactively |
| Two same-commit package or manifest hashes differ | Release acceptance fails; compare manifests before deployment |

### 5. Good / Base / Bad Cases

- **Good:** two complete builds of one pushed commit have the same package and
  manifest SHA-256; both admin/store CSS URLs are content-addressed.
- **Base:** a new commit legitimately changes source or CSS, creating a new
  release ID and a new content-addressed CSS URL.
- **Bad:** accepting a package merely because both builds have the same release
  ID while their SHA-256 values differ.
- **Bad:** fixing nondeterminism by using a permanent `app.css` URL, which would
  preserve stale browser/CDN caches after CSS content changes.

### 6. Tests Required

- Unit tests must assert VCS removal, symlink/file rejection, stage-boundary
  enforcement, known/unknown ThinkPHP headers, and idempotent UTC normalization.
- Frontend-entry tests must assert same-content convergence, content-change URL
  invalidation, exact two-link rewriting, collision rejection, and failed-write
  rollback.
- Build orchestration tests must assert install -> non-interactive script-free
  autoload dump -> normalization -> scans -> manifest order.
- Before changing deterministic assembly, preserve the first real package, run a
  second full `build --fetch`, and assert package SHA, manifest SHA, entry CSS
  paths, and nested-VCS count.

### 7. Wrong vs Correct

```text
Wrong: build twice -> release IDs match -> deploy despite different package SHA.
Correct: build twice -> package and manifest SHA both match -> deploy the reviewed package.

Wrong: composer dump-autoload --no-scripts
Correct: composer dump-autoload --no-dev --optimize --no-scripts --no-interaction
```
