# Miniapp Frontend Entry Point

> **Applies to:** `yoshop2.0-uniapp/` — uni-app WeChat mini-program and H5 client.

## Route Before Reading

- Page/copy/layout → `pages/`, then the referenced `components/` only when needed.
- Request or payload → `api/` and `utils/request/`.
- Payment behavior → `core/payment/` plus the matching API; upgrade out of Fast Fix.
- Shared user/session state → `store/`.
- Page registration/navigation → `pages.json`.

Do not search `node_modules/`, `dist/`, `static/`, or `uni_modules/` initially unless the changed source explicitly points there.

## Pre-Development Checklist

- Read only the relevant sections linked from the shared [Frontend Index](../../frontend/index.md).
- Vue page/component edit → [Component Guidelines](../../frontend/component-guidelines.md).
- State/session edit → [State Management](../../frontend/state-management.md).
- Payload normalization → [Type Safety](../../frontend/type-safety.md) and the matching contract.
- Service-order/payment/refund edit → [Service Order Contracts](../../frontend/service-order-contracts.md) and backend payment contracts; this is not Fast Fix.

## Quality Check

1. Run the narrowest relevant script from `yoshop2.0-uniapp/package.json`.
2. H5/browser smoke does not replace `build:mp-weixin:test` for WeChat-specific behavior.
3. Check `pages.json`, API field names, and platform conditionals when touched.
4. Do not edit generated `dist/` as source.
