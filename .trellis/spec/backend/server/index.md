# Backend Server Entry Point

> **Applies to:** `yoshop2.0/` — ThinkPHP APIs, services, models, callbacks, timers, and runtime scripts.

## Route Before Reading

1. API used by miniapp → start in `app/api/`.
2. Merchant-console API → start in `app/store/`.
3. Platform admin API → start in `app/admin/`.
4. Shared model/service/enum → inspect `app/common/` only after a caller proves it is shared.
5. Timer or scheduled convergence → start in `app/timer/`.

Do not search `vendor/`, `runtime/`, `runtim/`, `public/uploads/`, or generated public assets during initial diagnosis.

## Pre-Development Checklist

- Runtime/cache/local service work → read [Runtime Ownership Contract](../runtime-ownership-contract.md).
- Virtual payment, notify, refund, Apple inquiry, or payment-state work → read [Virtual Payment Contracts](../virtual-payment-contracts.md).
- Cross-layer field/status changes → read [Cross-Layer Thinking Guide](../../guides/cross-layer-thinking-guide.md).
- Search the exact route, method, field, enum, error code, or copy inside `yoshop2.0/` before expanding scope.

## Quality Check

1. Run `php -l` for every changed PHP file.
2. Verify the owning API surface (`api`, `store`, `admin`, or `timer`) and direct callers.
3. For contract changes, validate all consumers and the matching package Spec.
4. Payment/refund/state-machine work is never a Fast Fix and must follow its contract-specific tests.
