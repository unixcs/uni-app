# Implementation Plan — Immediate Refund Channel Convergence

## 1. Preserve Workspace State

- [x] Capture focused diffs for the three shared PHP files before editing.
- [x] Do not reset, checkout, reformat, or overwrite unrelated iOS refund/repeat-payment changes.

## 2. Payment Query Fix

- [x] Import/use the shared channel-class enum in cashier payment service.
- [x] Change the local-paid fast path so it returns only after virtual channel classification is resolved.
- [x] Preserve existing query snapshot, paid convergence, pending handling, and repeat-payment behavior.

## 3. Refund Preflight Fix

- [x] Add a public refund-domain channel convergence method with binding/paid-like validation.
- [x] Reuse existing WeChat virtual order query configuration and snapshot/classification path.
- [x] Reload and verify the persisted classification after query; keep unknown fail-closed.
- [x] Add sanitized structured observations for resolved, unresolved, and query-error outcomes.
- [x] Invoke preflight from `OrderRefund::getRefundGoods()` before the apply transaction.
- [x] Confirm known channels skip the extra query and iOS Apple routing remains unchanged.

## 4. Regression Coverage

- [x] Add `scripts/tests/immediate-refund-channel-convergence-contract.php`.
- [x] Cover paid-local + unknown-channel cashier behavior.
- [x] Cover refund preflight ordering before transaction.
- [x] Cover `order_type=0` and `order_type=7` classification via persisted query shape.
- [x] Assert unknown remains fail-closed.

## 5. Specs

- [x] Append the reusable immediate-refund channel convergence contract to `.trellis/spec/backend/virtual-payment-contracts.md` in English.

## 6. Validation

Run focused checks:

```bash
php -l yoshop2.0/app/api/service/cashier/Payment.php
php -l yoshop2.0/app/common/service/order/Refund.php
php -l yoshop2.0/app/api/model/OrderRefund.php
php yoshop2.0/scripts/tests/immediate-refund-channel-convergence-contract.php
php yoshop2.0/scripts/tests/ios-payment-channel-contract.php
php yoshop2.0/scripts/tests/non-ios-service-refund-regression-contract.php
php yoshop2.0/scripts/tests/virtual-payment-repeat-guard-contract.php
```

Run integration/regression checks supported locally:

```bash
cd yoshop2.0
php think virtual-payment:local-e2e --no-interaction
php think virtual-payment:local-e2e cleanup --no-interaction
IOS_REFUND_MATRIX_TEST=1 php scripts/tests/ios-refund-state-matrix-contract.php
```

Final gates:

```bash
git diff --check
python3 .trellis/scripts/task.py validate .trellis/tasks/07-14-immediate-refund-channel-convergence
```

## 7. Review Gates

- [x] Verify no external WeChat request occurs while refund row locks are held.
- [x] Trace Android `order_type=0`, Apple `order_type=7`, missing evidence, and query error end to end.
- [x] Verify preflight failure leaves no refund row and no state mutation beyond diagnostic query evidence.
- [x] Review every changed hunk against the pre-edit focused diff.
- [x] Run Trellis quality check and record results.

## 8. Manual Acceptance (requires authorized experience build/device)

- [ ] Pay a new Android virtual order and immediately submit refund once; no “支付渠道尚在确认中” error.
- [ ] Confirm the same transaction is classified before/refund submission and only one refund request exists.
- [ ] Confirm an iOS Apple transaction still shows App Store guidance and never calls developer refund.

No upload, deployment, or real transaction is authorized by this task.


## Verification Results (2026-07-14)

- PHP syntax checks passed for the three changed services/models and the new regression contract.
- `immediate-refund-channel-convergence-contract.php` passed, including paid+UNKNOWN cashier convergence, pre-transaction refund preflight, final-trade binding, and `order_type=0/7/missing` routing.
- `ios-payment-channel-contract.php`, `non-ios-service-refund-regression-contract.php`, and `virtual-payment-repeat-guard-contract.php` passed.
- `virtual-payment:local-e2e` passed and its generated fixtures were removed by the cleanup action.
- The 48-case / 1347-assertion iOS state matrix passed.
- iOS refund review/lock and two-connection concurrency contracts passed.
- Task-owned hunks were reconstructed against pre-edit snapshots and reviewed; no unrelated shared-file edits were overwritten.
- Full-workspace `git diff --check` and Trellis task validation passed.
- No deployment, experience-build upload, real payment, or real refund was performed. Manual device acceptance remains pending.
