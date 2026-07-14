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
