# Implementation Plan: 小程序支付成功后重复拉起收银台修复

## 0. Safety / Workspace Guard

- [x] Re-read `git status` and preserve all unrelated uncommitted refund/store changes.
- [x] Read frontend component, quality, state, type-safety specs and backend virtual-payment contract before editing.
- [x] Search every field/state value before changing it.

## 1. Add Regression Tests First

- [x] Add `yoshop2.0-uniapp/scripts/tests/payment-convergence-contract.cjs` that loads the cashier SFC and exercises methods with mocked APIs/timers.
- [x] Cover: double submit, native success + `onShow` race, pending→paid polling, pending exhaustion retaining old trade, explicit cancel, duplicate success finalization.
- [x] Add `yoshop2.0/scripts/tests/virtual-payment-repeat-guard-contract.php` or the narrowest executable equivalent for backend response/decision mapping.
- [x] Confirm tests fail against the current implementation for the intended reason.

## 2. Implement Frontend State Machine

- [x] Add explicit payment phase and active transaction state in `pages/checkout/cashier/index.vue`.
- [x] Make `handleSubmit()` return the complete orderPay → native payment → convergence Promise.
- [x] Handle backend `payment.state=paid|confirming|created`, defaulting missing state to `created`.
- [x] Route `onShow` recovery and native success through one convergence method keyed by `outTradeNo`.
- [x] Add bounded pending polling with order-status fallback.
- [x] Keep the button locked while creating, cashier-open, confirming or successful.
- [x] Preserve transaction recovery data on unknown/timeout; clear only on paid, explicit cancel or definitive terminal-unpaid.
- [x] Add one idempotent success finalizer and route existing paid paths through it.

## 3. Implement Backend Repeat Guard

- [x] Add a latest virtual attempt lookup that is scoped to the current order/store/user.
- [x] Before generating a virtual payment transaction, resolve existing attempt state.
- [x] Return additive `payment.state` values: `created`, `confirming`, `paid`.
- [x] For existing paid attempts, invoke the existing pay-success service and return `paid`.
- [x] For pending or unknown attempts, return the existing `outTradeNo` as `confirming` without payment signatures.
- [x] For explicitly closed/terminal-unpaid attempts, mark local trade closed where appropriate and allow one new attempt.
- [x] Add order-scoped concurrency protection around preflight/create; lock failure must fail closed.
- [x] Add trace stages without logging secrets.

## 4. Update Contracts / Documentation

- [x] Extend `.trellis/spec/backend/virtual-payment-contracts.md` with the “one active attempt per order” and fail-closed preflight contract if implementation confirms the convention.
- [x] Add the frontend convergence contract to the relevant frontend spec only if it is reusable beyond this page.
- [x] Keep spec docs in English.

## 5. Validation

Run narrow checks first:

```bash
node yoshop2.0-uniapp/scripts/tests/payment-convergence-contract.cjs
php -l yoshop2.0/app/api/service/cashier/Payment.php
php -l yoshop2.0/app/api/model/PaymentTrade.php
php yoshop2.0/scripts/tests/virtual-payment-repeat-guard-contract.php
```

Then project checks:

```bash
cd yoshop2.0-uniapp && npm run build:mp-weixin
cd /opt/yoshop && php -l yoshop2.0/app/api/controller/Cashier.php
```

If environment fixtures are available, also run the existing virtual-payment local/sandbox checks documented by the project. Do not claim real payment validation without a real-device transaction.

## 6. Review Gates

- [x] Verify compiled cashier contains the state field, unified convergence logic and pending polling.
- [x] Verify compiled payment module has only one actual `wx.requestVirtualPayment` invocation per call.
- [x] Search diff for accidental changes outside the owned files.
- [x] Review all paths that clear `tempUnifyData`.
- [x] Review all paths that can call `CashierApi.orderPay` and `Wechat.payment`.
- [x] Run Trellis quality check.

## 7. Manual Acceptance (requires user/device)

- [ ] Android experience build: one successful payment navigates to paid order without another cashier.
- [ ] Slow-network case: first query pending, then paid; no second payment.
- [ ] Rapid double tap: one `orderPay`, one `requestVirtualPayment`.
- [ ] Explicit cancel: no auto-query/pending label; a later explicit retry is allowed.
- [ ] Kill/reopen or background/foreground after payment: same old transaction is queried and converged.

## Rollback Points

1. Frontend state machine and tests form one rollback unit.
2. Backend repeat guard and its test form one rollback unit.
3. Spec updates roll back with the behavior they document.
4. Do not roll back or modify unrelated existing virtual-refund/iOS-refund changes in the workspace.

## Verification Results (2026-07-14)

- `node yoshop2.0-uniapp/scripts/tests/payment-convergence-contract.cjs` — PASS.
- PHP syntax checks for cashier payment service, payment-trade model, cashier controller, and local E2E command — PASS.
- `php yoshop2.0/scripts/tests/virtual-payment-repeat-guard-contract.php` — PASS.
- `php think virtual-payment:local-e2e --no-interaction` — PASS; generated fixtures were removed with the cleanup action.
- MySQL `GET_LOCK` / `RELEASE_LOCK` bind-and-release smoke test against the configured local database — PASS.
- `npm run build:mp-weixin` — PASS; fresh output timestamp `2026-07-14 20:00:18 +08:00` in the configured Windows mirror.
- Compiled cashier contains the payment state machine, cancellation marker, and joined query Promise; compiled payment module contains exactly one `requestVirtualPayment` invocation.
- `npm run build:h5` — PASS with existing Sass deprecation warnings only.
- `git diff --check` and trailing-whitespace scan — PASS.
- Real Android experience-build payment acceptance remains pending because it requires an authorized real-device transaction.
