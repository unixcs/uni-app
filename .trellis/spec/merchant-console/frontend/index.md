# Merchant Console Frontend Entry Point

> **Applies to:** `yoshop2.0-store/` — merchant-facing Vue 2 + Ant Design Vue console.

## Route Before Reading

- Page/button/table/modal → `src/views/`.
- Request/payload → matching module under `src/api/`.
- Route or menu exposure → `src/router/`, `src/permission.js`, then backend store-menu/permission source if evidence requires it.
- Reusable UI → `src/components/` only after confirming an existing shared component is involved.

Do not search `node_modules/`, `dist/`, or backend code during initial UI-only diagnosis.

## Pre-Development Checklist

- Read the relevant shared [Frontend Index](../../frontend/index.md) entry.
- Vue SFC/modal/form → [Component Guidelines](../../frontend/component-guidelines.md).
- Build/review → [Quality Guidelines](../../frontend/quality-guidelines.md).
- Order/refund page → [Service Order Contracts](../../frontend/service-order-contracts.md).
- Goods forms → [Goods Form Contracts](../../frontend/goods-form-contracts.md).

## Quality Check

1. Prefer focused inspection, then `npm --prefix yoshop2.0-store run lint:nofix` or the narrowest meaningful build/test.
2. Contract changes require checking `yoshop2.0/app/store/` and relevant consumers.
3. Permission/menu behavior is not a cosmetic Fast Fix once backend authority is involved.
4. Treat `yoshop2.0/public/store` output as deployment output, not source.
