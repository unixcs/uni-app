# Directory Structure

> How frontend code is organized across the miniapp client and merchant console.

---

## Overview

This repository does **not** have a single `src/` frontend. It has two independently structured applications:

- `yoshop2.0-uniapp/` — end-user client for H5 / WeChat miniapp
- `yoshop2.0-store/` — merchant/admin console

New work should extend the structure of the app you are touching instead of introducing a new top-level pattern.

## Directory Layout

### Miniapp (`yoshop2.0-uniapp`)

```text
api/                  request wrappers grouped by domain
common/               enums, static data, model helpers
components/           reusable UI components
core/                 bootstrap, config, payment, mixins, app helpers
pages/                route entry pages grouped by feature
store/                Vuex modules + getters
utils/                request wrapper, validators, small utilities
scripts/              local build / DevTools / release helpers
```

Representative examples:
- Page entry: `yoshop2.0-uniapp/pages/checkout/index.vue`
- Feature page pair: `yoshop2.0-uniapp/pages/feedback/index.vue`, `pages/feedback/detail.vue`
- Reusable components: `components/first-login-popup/index.vue`, `components/privacy-popup/index.vue`
- Shared domain helper: `common/model/Store.js`
- Build tooling: `scripts/build-mp-weixin.cjs`

### Merchant console (`yoshop2.0-store/src`)

```text
api/                  axios wrappers grouped by backend module
components/           shared UI pieces
config/               router and runtime config
layouts/              shell layout components
store/                Vuex modules and app mixins
utils/                axios setup and generic helpers
views/                route-level pages grouped by business domain
```

Representative examples:
- Route config: `yoshop2.0-store/src/config/router.config.js`
- Route page: `src/views/order/Index.vue`
- Detail page: `src/views/order/Detail.vue`
- Nested tool dialog: `src/views/order/tools/Export.vue`
- Modal module barrel: `src/views/store/address/modules/index.js`

## Module Organization

### Miniapp rules

- Put route-entry screens under `pages/<feature>/`.
  - Example: feedback flow lives under `pages/feedback/`.
- Put reusable UI under `components/<feature>/index.vue` when it can be mounted from multiple pages.
  - Example: `components/privacy-popup/index.vue`.
- Keep raw request paths inside `api/*.js`; pages import API modules, not hard-coded URLs.
  - Example: `pages/feedback/index.vue` imports `@/api/feedback` and `@/api/upload`.
- Put cross-page app behavior under `core/` or `common/`, not inside one page file.

### Merchant console rules

- Route-level pages live at `src/views/<domain>/...` and are wired from `router.config.js`.
- When one view owns several modal/forms/tool panels, place them under a sibling `modules/` or `tools/` directory.
  - Example: `src/views/store/address/modules/`
  - Example: `src/views/order/tools/`
- Keep backend request wrappers in `src/api/<domain>/...` and import them into views/modules.
- New menu entries should reuse existing domain folders when the behavior belongs to that domain; do not create an isolated top-level view tree for one screen unless it is a real new module.

## Naming Conventions

- Route-entry Vue files typically use `Index.vue`, `Detail.vue`, `Create.vue`, `Update.vue`.
- Reusable form/dialog components use descriptive PascalCase file names such as `AddForm.vue`, `EditForm.vue`, `Export.vue`.
- Domain folders and API files are lower-case (`api/order/index.js`, `pages/feedback/`).
- Small barrel files use `index.js` to re-export sibling modules.

## Anti-patterns

- Do not put feature-specific API calls directly in global utilities just to avoid creating a domain API file.
- Do not add a third frontend architecture layer (for example `features/` or `hooks/`) unless the existing codebase has already adopted it broadly.
- Do not duplicate the same business field mapping in both `pages/` and `components/` when it can live in one page/container.

## Examples to copy

- Miniapp page + API split: `pages/feedback/index.vue` + `api/feedback.js`
- Miniapp component extraction: `components/first-login-popup/index.vue`
- Merchant route page + tool module split: `views/order/Index.vue` + `views/order/tools/Export.vue`
- Merchant modal barrel pattern: `views/store/address/modules/index.js`
