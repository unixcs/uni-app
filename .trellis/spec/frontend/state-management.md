# State Management

> How state is managed in the miniapp client and merchant console.

---

## Overview

Both frontend apps use Vuex, but only for app/session-level concerns. Most screen data stays local to the page/module that owns it.

## State Categories

### Global app/session state

Use Vuex for values that many screens need or that define app-wide behavior.

Miniapp examples (`yoshop2.0-uniapp/store/modules/`):
- `app.js`
- `user.js`
- `theme.js`
- `page.js`

Merchant console examples (`yoshop2.0-store/src/store/modules/`):
- `app.js`
- `user.js`
- `permission.js`
- `async-router.js`

Typical contents:
- auth/session state
- theme/layout settings
- router/permission state
- page configuration needed across screens

### Local page/component state

Keep fetched detail records, filters, modal visibility, submit flags, and form values inside the owning page/component.

Examples:
- `pages/feedback/index.vue` keeps `formData`, `imageList`, `recordList`, `recordLoading` locally.
- `pages/checkout/index.vue` keeps `remark`, `gamePlatform`, `adultConfirm`, `order`, `setting` locally.
- `views/order/Index.vue` owns order filter state and list rendering.
- `views/content/editor/Index.vue` owns `isLoading`, `record`, and the current Ant Design form instance.

## When to Use Global State

Promote state to Vuex only when at least one of these is true:
- the value is needed across many unrelated screens;
- it represents login/session identity;
- it affects app shell behavior (theme, menu, permissions, layout);
- it must survive navigation and be shared without prop drilling.

Do **not** promote one-screen form state or one-off API responses into Vuex just to avoid passing props.

## Server State

This repo does not use React Query / SWR / Pinia-style server caching.

Observed pattern:
- define request wrappers in `api/*.js`;
- call them from page/module methods;
- store the response in local component state;
- re-fetch explicitly after successful submit or filter change.

Examples:
- `pages/checkout/index.vue` calls checkout APIs and rehydrates local order data.
- `views/content/editor/Index.vue` fetches detail, then submits updates back through the same domain API.
- `views/order/Index.vue` fetches list data according to current filters.

## Persistence and derived state

- Merchant console app settings are persisted through the `store` package inside Vuex mutations (`src/store/modules/app.js`).
- Derived state generally lives in `computed` properties or Vuex getters rather than extra duplicated fields.
  - Example: `showCouponEntry`, `isServiceCheckout`, `currentIssueTypeName`.

## Common Mistakes

- Mirroring backend response data into Vuex when only one page uses it.
- Storing modal open/close flags globally.
- Adding duplicate state fields instead of computing from a canonical source.
- Mixing permission/router state with business data in the same Vuex module.
