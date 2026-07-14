# Design — Immediate Refund Channel Convergence

## 1. Problem Model

The system currently conflates two state machines:

```text
Payment fact:  UNPAID -> PAID
Channel fact:  UNKNOWN -> NON_IOS | IOS_APPLE
```

`xpay_goods_deliver_notify` can advance the payment fact without advancing the channel fact because the observed callback has no `order_type`. A later `query_order` supplies `order_type`, but the cashier currently skips that query after local payment success. Refund routing then correctly refuses `UNKNOWN`, but only a periodic timer repairs it.

The fix must make channel convergence synchronous at both natural boundaries without weakening the fail-closed refund rule.

## 2. Boundaries and Owned Files

Primary implementation:

- `yoshop2.0/app/api/service/cashier/Payment.php`
- `yoshop2.0/app/common/service/order/Refund.php`
- `yoshop2.0/app/api/model/OrderRefund.php`

Regression coverage and contract:

- `yoshop2.0/scripts/tests/immediate-refund-channel-convergence-contract.php`
- `.trellis/spec/backend/virtual-payment-contracts.md`

No frontend source change is required: the cashier already calls backend trade query after native payment success, and the refund page already loads refund goods before submit.

## 3. Payment-Side Convergence

Current fast path:

```text
local order paid -> return true
```

New fast path:

```text
local order paid AND channel classified -> return true
local order paid AND channel UNKNOWN -> query WeChat -> merge query_order snapshot -> classify -> return paid
```

The existing query persistence and `PaymentTrade::classifyChannelClass()` remain authoritative. The existing pay-success service remains the only order-payment convergence path and is already idempotent for an order that is locally paid.

If the upstream query fails, existing pending/error behavior remains in force; no payment attempt is created.

## 4. Refund-Side Preflight

Add a public domain operation on the existing refund service:

```text
convergeVirtualPaymentChannelForRefund(order, trade)
```

Behavior:

1. Return immediately for non-virtual or already-classified trades.
2. Validate that order/trade/store/user binding is consistent and that the transaction is paid-like.
3. Reuse the existing authenticated `query_order` machinery.
4. Normalize `virtual_refund.query_result` to the returned WeChat order object (the shape already consumed by channel classification), while retaining the complete response as `query_response` for audit.
5. Reload the trade from storage.
6. Return only after classification becomes `NON_IOS` or `IOS_APPLE`; otherwise fail closed.
7. Emit structured success/failure observation without credentials or signatures.

`OrderRefund::getRefundGoods()` invokes this preflight after validating the paid order and resolving the final trade, but before `apply()` enters its database transaction. This covers both navigation to the apply page and a direct POST request. The transaction then re-locks order/refunds/final trade in the established order and makes the existing iOS/non-iOS decision from persisted facts.

## 5. Transaction and Race Analysis

No WeChat query is added while holding `order -> refunds -> trade` row locks.

Possible race between preflight and transaction:

- Same final trade: transaction reads the newly persisted monotonic classification.
- Trade binding changes: transaction reads the new final trade; existing fail-closed logic prevents an unknown trade from reaching developer refund.
- Concurrent timer/cashier query: `PaymentTrade::mergePayloadSnapshot()` serializes updates with the trade row lock and classification is monotonic.
- Concurrent refund submit: existing order/refund/trade lock order and active-refund checks remain authoritative.

## 6. Error Contract

| State | Result |
|---|---|
| Known NON_IOS | No extra query; existing developer refund |
| Known IOS_APPLE | No extra query; existing App Store flow |
| UNKNOWN + query yields order_type 0/non-7 | Persist NON_IOS; continue same request |
| UNKNOWN + query yields order_type 7 | Persist IOS_APPLE; continue same request |
| Query transport/API/shape error | No refund row/API; safe error |
| Query succeeds but no classification evidence | Remain UNKNOWN; no refund row/API; safe error |

The message may tell the user the channel result could not yet be confirmed, but the implementation must not require a timer or user retry when the synchronous query already contains evidence.

## 7. Compatibility

- `channel_class` values and schema are unchanged.
- Existing snapshot keys and classification helper are reused.
- Known virtual trades and all non-virtual payment methods keep their current branches.
- Timer reconciliation remains as defense in depth.
- iOS risk locking, merchant review, refund notifications, and duplicate-payment loser handling are untouched.

## 8. Rollback

1. Payment fast-path condition is one independent rollback hunk.
2. Refund preflight service and its `OrderRefund` call site form one rollback unit.
3. Test/spec changes roll back with their behavior.
4. Never revert unrelated edits already present in the three shared PHP files.
