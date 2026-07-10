# Type Safety

> Shape and validation conventions in this JavaScript-first frontend codebase.

---

## Overview

This repository does **not** use TypeScript in the application source of either frontend app. Type safety is achieved through:

- disciplined request/response contracts;
- small validators and utility guards;
- enums / constants / model helpers;
- defensive defaults when reading backend data.

## Type Organization

There is no dedicated `types/` layer today. Instead, shape knowledge lives in:

- `common/enum/` and `common/constant/` in the miniapp
- `common/model/` helper files such as `common/model/Store.js`
- API wrapper boundaries in `api/*.js`
- component-level normalization methods
- Trellis contract docs for cross-layer changes

Examples:
- `yoshop2.0-uniapp/common/enum/coupon.js`
- `yoshop2.0-uniapp/common/model/Store.js`
- `yoshop2.0-store/src/api/order/index.js`
- `.trellis/spec/frontend/service-order-contracts.md`

## Runtime Validation

Use lightweight runtime validation close to user input and boundary parsing.

Examples:
- `yoshop2.0-uniapp/utils/verify.js` provides validators such as `isMobile`.
- `pages/checkout/index.vue` checks `gamePlatform`, `gameAccountId`, `contactMobile`, and `adultConfirm` before submit.
- `views/content/editor/Index.vue` uses `lodash.pick` to restrict editable form fields.
- `components/privacy-popup/index.vue` guards missing runtime APIs with `typeof uni.getPrivacySetting !== 'function'`.

## Common Patterns

- Prefer explicit defaults when reading nested or optional data:
  - `const order = this.order || {}`
  - `result.data.detail || {}`
- Use `Number(...)`, boolean coercion, and `Object.prototype.hasOwnProperty.call(...)` where the payload can vary.
- Reuse existing enums/constants instead of scattering magic numbers/strings across pages.
- Keep backend field names stable across layers once a contract doc has been written.

## Forbidden Patterns

- Do not introduce speculative TypeScript-only structure in one corner of the app while the rest remains plain JS.
- Do not rename backend fields ad hoc per screen when a shared contract already exists.
- Do not trust optional nested fields without a fallback/default.
- Do not bury validation inside unrelated helpers when the page owns the input contract.

## Common Mistakes

- Changing one layer from `snake_case`/existing contract names to custom `camelCase` aliases without updating every consumer.
- Forgetting to normalize empty responses before calling UI methods.
- Copying a field list into multiple places instead of centralizing the contract in a spec doc or a helper.
