# Hook Guidelines

> How reusable logic is shared in a codebase that does not rely on a modern hooks/composables architecture.

---

## Overview

Despite the filename, this repository is **not** hook-centric:

- The miniapp mostly uses the Vue Options API.
- The merchant console is Vue 2 Options API.
- Shared behavior is usually implemented with **plain modules**, **Vuex**, or a **small number of mixins**, not `useXxx` composables.

Document and extend the existing reality instead of inventing a new hook layer.

## Existing reusable-logic patterns

### 1. Plain helper modules

Use plain JS helpers for navigation, toast helpers, config, payment, validation, and request wrappers.

Examples:
- `yoshop2.0-uniapp/core/app/index.js`
- `yoshop2.0-uniapp/utils/verify.js`
- `yoshop2.0-store/src/utils/request.js`

### 2. Mixins for app-wide behavior

Mixins exist, but they are used sparingly and only for genuinely shared runtime behavior.

Examples:
- `yoshop2.0-uniapp/core/mixins/app.js`
- `yoshop2.0-store/src/store/app-mixin.js`
- `yoshop2.0-store/src/store/device-mixin.js`
- `yoshop2.0-store/src/store/i18n-mixin.js`

### 3. Page lifecycle methods

Most fetching and screen-specific side effects stay inside the page/component lifecycle:
- miniapp: `onLoad`, `onShow`, `created`
- merchant console: `created`, modal open/submit methods

Examples:
- `pages/checkout/index.vue` loads data from `onShow`
- `pages/feedback/index.vue` triggers record loading when the tab changes
- `views/content/editor/Index.vue` fetches detail in `created`

## When to extract shared logic

Extract only when the same behavior appears in multiple places **and** the extracted form matches existing repo patterns.

Preferred order:
1. Small pure helper in `utils/` or `core/`
2. Domain API wrapper in `api/`
3. Vuex state if the value is truly global/session-scoped
4. Mixin only when lifecycle/stateful behavior is reused across multiple screens

## What not to add casually

- Do not introduce React-style hooks or a new composables directory just for one task.
- Do not migrate one page to Composition API while the surrounding feature still uses Options API.
- Do not hide feature-specific submit/fetch logic in a global mixin.

## Naming Conventions

- Plain helpers use domain names (`checkLogin`, `showToast`, `storeInfo`, `detail`, `list`).
- Mixins are named by cross-cutting concern (`app-mixin`, `device-mixin`, `i18n-mixin`).
- If you must add a reusable logic file, prefer descriptive domain naming over generic names like `useCommon` or `useUtils`.

## Common Mistakes

- Extracting code too early, then making the shared helper full of feature-specific branches.
- Using a mixin when a normal imported function would have been clearer.
- Creating singleton module state for page-local UI state that should stay in `data()`.
