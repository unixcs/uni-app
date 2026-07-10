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
- **Manual edits to generated artifacts without intent**: compiled output should change only when you deliberately produce a deployment commit (for example `yoshop2.0/public/store/*`).
- **Cross-layer drift**: do not add UI fields, filters, exports, or status labels without checking every consumer and updating the matching contract doc.
- **Direct request sprawl**: do not bypass `api/*.js` wrappers from arbitrary components.
- **Speculative refactors during feature work**: keep the Vue 3 miniapp and Vue 2 merchant console patterns stable unless the task is explicitly architectural.

## Required Patterns

- Search for a field / menu path / config key before changing it.
- Keep request wrappers, page state, and UI components aligned with the existing module split.
- When adding or fixing a shared business contract, update the relevant Trellis spec in the same task.
- Separate deploy artifacts from source-code commits when both are needed.
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
npm run build:mp-weixin
```

Use H5/manual verification only when the touched flow requires it.

### Cross-layer / backend-adjacent changes

When frontend work depends on PHP-side fields or commands, pair frontend checks with targeted backend syntax validation:

```bash
php -l <touched-php-file>
```

## Testing Expectations

- Favor targeted validation over blanket rebuilds, but do run a real build for the touched app before closing substantial work.
- For user-facing forms and platform-specific behavior, keep a manual smoke checklist in the task artifact or final report.
- For deployment-style updates to `yoshop2.0/public/store`, verify the built store output was actually synced.

## Code Review Checklist

Before finalizing a frontend task, confirm:
- request params and backend field names still match;
- route/menu/permission paths are aligned;
- list/detail/export views show the same contract fields;
- local-only noise (cache files, runtime files, line endings) is not mixed into commits;
- spec docs are updated if the task taught us a reusable rule.
