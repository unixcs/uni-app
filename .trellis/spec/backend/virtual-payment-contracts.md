# Virtual Payment Contracts

## Scenario: iOS Apple virtual-payment refund inquiry

### 1. Scope / Trigger
- Trigger: WeChat iOS virtual payment uses Apple payment for iOS users. Apple payment does **not** allow developer-initiated refunds; users request refunds in the App Store.
- Backend must support WeChat's Apple refund inquiry event and avoid routing iOS Apple orders to `/xpay/refund_order`.

### 2. Signatures
- Notify endpoint: `POST /notify/virtualPayment`
- Event: `xpay_subscribe_ios_refund_query_notify`
- Handler response shape: `IosRefundQueryResponse`
  - `result_code: int` (`0` = suggest/refund allowed, `1` = suggest reject)
  - `result_info: string`
  - `evidence: string` (required, auditable reason)

### 3. Contracts
- Incoming inquiry payload should be matched to local trade by `pay_order_id` first; tolerate casing variants only for compatibility.
- Persist inquiry evidence under `payment_trade.payload_snapshot`:
  - `ios_refund_query_notify`
  - `virtual_refund.ios_refund_required = true`
  - `virtual_refund.ios_refund_query_decision`
- iOS Apple trade detection must use persisted upstream evidence, especially `query_order.result.order.order_type == 7`, plus prior inquiry snapshots.
- `query_order.status in [2,3]` must both be treated as paid-like states for diagnostics and evidence interpretation; `3` means the order is already paid and waiting for downstream delivery/consumption.
- Once local refund state is `waiting_ios_apple_refund`, background refund sync must stop active `query_order` polling for that trade and wait for inquiry / refund notifications instead.
- Final money movement remains Apple/WeChat controlled. Local completion should still converge from `xpay_refund_notify`.

### 4. Validation & Error Matrix
| Case | Behavior |
|------|----------|
| Valid local virtual trade | Lock the bound order, persist the inquiry attempt, and decide from service stage + merchant audit |
| Local trade missing / binding ambiguous | Persist a zero-binding audit attempt, log an alert, return `result_code=1` with non-empty evidence |
| Service not started | Return `result_code=0`, create/reuse one refund tracking row, permanently lock service |
| Service started + audit WAIT/REJECTED | Return `result_code=1`, but still permanently lock service because Apple may override the suggestion |
| Service started + audit REVIEWED | Return `result_code=0` and keep service locked |
| Service completed | Return `result_code=1`, record a high-risk event; a later successful refund notify still wins as money-state fact |
| `query_order.status=2` | Treat as paid |
| `query_order.status=3` | Treat as paid and pending downstream delivery/consumption |
| Existing iOS Apple order enters local refund service | Mark `virtual_refund.status=waiting_ios_apple_refund`, do not call `/xpay/refund_order` |
| Android/non-Apple virtual order enters refund service | Keep existing `/xpay/refund_order` behavior |
| Apple refund succeeds upstream | `xpay_refund_notify` remains responsible for local refund finalization |

### 5. Good/Base/Bad Cases
- Good: App Store refund inquiry receives a dedicated `IosRefundQueryResponse` instead of generic `{ErrCode, ErrMsg}`.
- Base: Android virtual refunds continue to call developer refund API.
- Bad: iOS Apple order calls `/xpay/refund_order` and returns `IOS订单不支持开发者发起退款` to users/admins.

### 6. Tests Required
- Syntax-check changed PHP files with `php -l`.
- Callback contract review: verify event dispatch returns `result_code/result_info/evidence` for `xpay_subscribe_ios_refund_query_notify`.
- Refund service review: verify `order_type=7` snapshots short-circuit before access-token/refund-order setup.
- Observability review: confirm logs can distinguish local refund request, Apple inquiry callback, and WeChat refund-finalization callback.

### 7. Wrong vs Correct
- Wrong: Treat all `wechat_virtual` refunds as developer-initiated and call `/xpay/refund_order`.
- Correct: Treat iOS Apple orders as App Store refund flows; record local waiting state and answer Apple's inquiry with auditable evidence.

### 8. Observability
- Log local iOS refund intent when a trade is converted to `waiting_ios_apple_refund`.
- Log Apple inquiry decisions with `pay_order_id`, local trade match result, and refund suggestion.
- Log WeChat refund notify outcomes, especially `completed`, `pending_refund_binding`, and `pending_refund_ready`.
- When background sync sees `waiting_ios_apple_refund`, log `skip_query_order` instead of silently polling.


## 9. Callback authentication and refund-notify convergence

- Plaintext push mode must verify `signature = sha1(sort(token, timestamp, nonce))` before dispatch.
- Safe push mode must verify and decrypt the outer `Encrypt` body with `msg_signature`, `timestamp`, `nonce`, EncodingAESKey, token, and AppID.
- When safe mode requires a non-empty iOS inquiry business response, encrypt `IosRefundQueryResponse` with the same AppID/token/EncodingAESKey and return the configured JSON/XML outer wrapper (`Encrypt`, `MsgSignature`, `TimeStamp`, `Nonce`). Only empty string or literal `success` may remain unencrypted.
- `Encryptor::decrypt()` already verifies `msg_signature`, AppID, and ciphertext integrity. After decryption, the inner business payload no longer contains `Encrypt`; it **must not** be routed through plaintext `signature` verification again.
- Missing or invalid signatures must be rejected. Authenticated business ambiguity is fail-closed; authentication failures must never receive a synthetic business decision.
- Public callback responses must not include raw exception messages. Keep exception details in server logs.
- Every authenticated `xpay_refund_notify` is appended to the durable iOS refund event ledger. `normalizeRetCode()` accepts only integer `0` or canonical string `"0"` as success.
- Before order mutation, resolve payment-attempt role under the order lock:
  - winning trade (`trade_id == order.trade_id`) may enter order-level convergence;
  - trusted `duplicate_payment` loser uses trade-only convergence and must not cancel/freeze the normal order or create a service-refund row;
  - conflict fails closed and alerts.
- Winning-trade success has two postconditions:
  - mandatory order-level safety convergence: order cancelled, service fulfillment disabled, trade REFUND, risk REFUNDED;
  - refund-row accounting: unique candidate completed, none auto-created, multiple candidates left untouched and recorded as `refund_binding_ambiguous`.
- Multiple refund rows must not block order-level safety convergence or force endless upstream retries. Duplicate notifications must reuse the event/convergence facts and never repeat side effects.
- A durable convergence worker owns PENDING events, including `provide_goods=sending` TTL recovery; callback delivery is not the only retry mechanism.

### Required regression cases

1. Valid plaintext signature; missing and invalid plaintext signature.
2. Valid safe-mode `msg_signature`; missing and invalid safe-mode signature; encrypted JSON/XML decision responses decrypt back to `result_code/result_info/evidence`.
3. Three repeated Apple inquiry calls, all with non-empty `evidence` and a local execution time below the upstream 3-second response budget.
4. Successful refund notify before any local refund row; duplicate notify; unique WAIT row reuse; ambiguous active rows.
5. Every database-mutating regression must run inside a transaction and prove rollback restoration.

### Concurrency and damaged-binding recovery

- All local finalization transactions must acquire row locks in the same order: `order -> order_refund -> payment_trade`. The Apple-notify auto-record and finalization paths must not reverse order/refund locking.
- If snapshot binding is missing but exactly one completed service-refund row exists, reuse it and idempotently repair order, trade, and snapshot state.
- If multiple service-refund candidates exist, never guess or complete one; persist `refund_binding_ambiguous`, but winning-trade order-level safety convergence still completes and callback returns business success.
- Regression coverage must include unique/none/multiple refund rows, duplicate-payment loser trade, missing second callback, PENDING convergence worker recovery, and idempotent entitlement reversal.

### Production acceptance signature

```text
php think virtual-payment:sandbox-check
  --out-trade-no=<merchant order id>
  --expect-ios-refund-query
  --expect-refunded
  --expect-no-duplicate-refund
```

- `--expect-ios-refund-query` fails unless at least one authenticated inquiry attempt, its `result_code/evidence`, and the bound order risk state are persisted; it must not assume every inquiry is `suggest_refund`.
- `--expect-refunded` fails unless the successful refund notification is persisted and all four local projections have converged.
- The command is a read-only local-state acceptance gate. It does not prove Apple/bank receipt and must not replace user receipt evidence.

### Public callback entry and readiness probe

- Files under `public/notice/` must load the project autoloader with `dirname(__DIR__, 2) . '/vendor/autoload.php'`. Using `__DIR__ . '/../vendor/autoload.php'` incorrectly resolves to `public/vendor/autoload.php` and makes every upstream callback fail with HTTP 500 before routing/authentication.
- Both `virtualPayment.php` and the legacy alias `wechatVirtual.php` must point to the same valid project-root autoloader and `/notify/virtualPayment` route.
- Release preflight must run `--probe-notify-endpoint`. The probe sends a signed URL-verification GET and requires the public endpoint to return the exact random `echostr`; checking only that a local file/route exists is insufficient.
- When `--out-trade-no` audits a real trade, configured env may be production or sandbox, but it must equal the persisted trade env and the corresponding production/sandbox app key must exist.

### Safe-mode public E2E release check

- Before release, send an authenticated encrypted inquiry for a unique unknown trade through the actual public HTTPS callback. Require HTTP 200, the complete `result_code` / `result_info` / `evidence` contract, non-empty unknown-trade evidence, no exception leakage, no business trade creation, and end-to-end latency below 3 seconds.
- The fixture must load credentials from runtime configuration and must never print or persist token, EncodingAESKey, `msg_signature`, signed query URLs, or ciphertext.
- The lighter `--probe-notify-endpoint` URL-verification check must require both final HTTP status 200 and an exact random `echostr` match; record only the endpoint, status, elapsed time, and match boolean.
- A synthetic unknown-trade E2E proves callback mechanics only. It does not replace a real authenticated Apple inquiry, a successful refund notification, or user receipt evidence.

### Evidence credential redaction

- Readiness evidence must sanitize upstream responses recursively before disk persistence. At minimum redact values whose keys contain token, secret, signature, app_key, session_key, or aes_key.
- Perform status interpretation against the in-memory upstream response, then persist only the sanitized copy. Redaction must not weaken acceptance checks.
- Curated task evidence must be scanned as well; moving a runtime response into `.trellis/tasks/` does not make embedded operational tokens safe to commit.

## Payment channel classification

- `query_order.result.order.order_type` is an integer enum, not a truthy flag.
- `order_type == 7` is iOS Apple; any explicitly present non-7 integer, including **`0`**, is positive NON_IOS evidence.
- Missing `order_type` remains UNKNOWN unless another strong snapshot proves the channel. Never use `empty()` or `> 0` to discard `0`.
- After changing classification rules, run channel backfill in dry-run and apply modes, then verify class counts and known real orders.


## Scenario: iOS Apple merchant review and irreversible service risk lock

### 1. Scope / Trigger
- Trigger: a local iOS Apple service-refund request, an authenticated Apple refund inquiry, or a trusted Apple refund-success notification for the order's final payment trade.
- Goal: stop the original order from starting or completing service after refund risk exists, without changing Android or non-Apple refund behavior.
- Out of scope: points, settlement, cumulative spend, coupons, stock, generic effect ledgers, new alert tables, metrics/heartbeat services, or new worker/lease infrastructure.

### 2. Signatures
- Order risk is monotonic: `NONE(0) -> LOCKED(10) -> REFUNDED(20)`.
- The order stores the first lock source/time. Valid sources are `LOCAL_APPLY`, `APPLE_INQUIRY`, and refund-notify recovery.
- `payment_ios_refund_inquiry` stores one row for each authenticated inquiry, including trade/order binding, service and merchant-review snapshots, decision, evidence, fingerprint, and response timing.
- `migration_key` is nullable and unique only for idempotent legacy inquiry migration; live inquiry fingerprints are not unique because every authenticated retry is retained.

### 3. Contracts
- Creating a local iOS service-refund row and changing `NONE -> LOCKED(source=LOCAL_APPLY)` are one transaction.
- A correctly bound authenticated inquiry also establishes or preserves `LOCKED`. Apple inquiry rejection, elapsed time, screenshots, and merchant rejection never unlock the order.
- Merchant review and Apple risk are independent. `WAIT` and `REJECTED` recommend `result_code=1`; `REVIEWED` recommends `result_code=0`. Completed service recommends `1` regardless of review state.
- Every authenticated inquiry reads the latest service and review state while holding the order lock. Repeated payloads may therefore receive a different decision after merchant review changes; idempotency applies only to side effects.
- Missing, non-final, cross-store, cross-user, cross-order, or otherwise conflicting trade bindings fail closed with `result_code=1` and do not mutate an unrelated order.
- An Apple inquiry must additionally bind to a trade classified by `PaymentTrade::isIosAppleVirtualTrade()`. A valid Android/non-Apple virtual trade ID still fails closed, is retained only as a zero-order audit event, and must not create a service-refund row or establish Apple risk.
- Local apply, start/complete service, merchant review, inquiry, and refund-success handling use the shared lock order `order -> relevant order_refund -> final payment_trade -> inquiry` and repeat authorization inside the transaction.
- `startService()` and `completeService()` require risk `NONE`, no active iOS service-refund request, and a still-valid service state after locking.
- iOS service refunds always expose `can_cancel=false`. Do not add a cancellation route; any existing generic cancellation path must reject this case. `LOCKED/REFUNDED` cannot create another local refund row.
- Existing `provide_goods` send and retry paths re-read order risk immediately before sending and skip `LOCKED/REFUNDED`; no new scheduler or worker is introduced.
- A trusted refund-success notification for the final iOS trade converges order, service eligibility, trade, risk, and the single relevant refund row idempotently. A duplicate-payment loser keeps the existing trade-only behavior.

### 4. Validation & Error Matrix
| Condition | Required result |
|---|---|
| Local apply before service | create REVIEWED refund row and LOCKED atomically; show Apple guidance |
| Local apply during service | create WAIT refund row and LOCKED atomically; wait for merchant review |
| Local apply after completion | reject without creating a refund row |
| Inquiry before service | recommend `0`, create/retain one refund row, LOCKED, append inquiry history |
| Inquiry during service with WAIT/REJECTED | recommend `1`, preserve LOCKED, append inquiry history |
| Inquiry during service with REVIEWED | recommend `0`, preserve LOCKED, append inquiry history |
| Inquiry after completion | recommend `1`, preserve service history, LOCKED, append inquiry history |
| Same payload after WAIT becomes REVIEWED | later inquiry recomputes to `0`; no duplicate refund row |
| Invalid or conflicting binding | recommend `1`; no unrelated order/refund/risk mutation |
| LOCKED/REFUNDED start or complete request | backend rejects; service state is unchanged |
| iOS cancellation attempt | `can_cancel=false`; no new route; existing generic path rejects without mutation |
| `provide_goods` send/retry sees LOCKED/REFUNDED | skip the send and record a structured log |
| Trusted final-trade refund success | order/service/trade/risk/refund converge to the refunded terminal state |
| Duplicate refund-success notification | same terminal state, no duplicate refund row |
| Duplicate-payment loser refund success | loser trade only; winning order and risk stay unchanged |
| Historical `ios_refund_required` without stronger evidence | backfill makes no change |

### 5. Tests Required
- Contract tests cover every inquiry decision, latest-review recomputation, binding failure, duplicate callback, and refund-success convergence.
- Real two-connection tests cover local apply/inquiry versus start/complete and merchant review versus inquiry.
- Service and `provide_goods` tests prove backend enforcement; frontend button state is not accepted as a safety boundary.
- Backfill dry-run/apply/dry-run covers a real local iOS refund row, authenticated legacy inquiry, trusted success notification, binding conflict, and `ios_refund_required` alone; the final dry-run reports zero changes.
- Compatibility tests prove Android/non-Apple behavior is unchanged and no cancellation route or unrelated financial reversal is added.

### 6. Wrong vs Correct
- Wrong: unlock after merchant rejection, elapsed time, or a screenshot; freeze only after Apple inquiry; return `0` before the lock/refund/inquiry transaction commits.
- Correct: local apply or a bound authenticated inquiry permanently locks the original order; only trusted refund success advances it to `REFUNDED`.

## Scenario: one active virtual-payment attempt per order

### 1. Core invariant
- One explicit user payment intent may have at most one active `wechat_virtual` transaction for the same `store_id + order_id`.
- Lifecycle refresh, recovery, query retry, and uncertain upstream results must converge the existing `out_trade_no`; they must never create another payment attempt.
- An unknown result is not a failed payment. Only an explicit upstream `CLOSED` state, or a verified client-cancel path defined below, permits a new attempt.

### 2. Preflight decision contract
Before calling WeChat virtual-payment order creation, read the latest store-scoped virtual trade and apply this matrix:

| Existing attempt | Required response | New WeChat order allowed |
|---|---|---|
| Local or remote paid | Converge through the existing order pay-success service and return `payment.state=paid` | No |
| Remote `CREATED` / `PAYING` | Return the same `outTradeNo` with `payment.state=confirming` | No |
| Query timeout, network error, malformed response, or unknown status | Return the same attempt as `confirming` and fail closed | No |
| Remote or local `CLOSED` | Mark the local attempt closed when needed | Yes |
| Explicit client cancel bound to the same trade and verified as not paid | Close that local attempt according to the approved cancel rule | Yes |
| Refunded completed trade | Return `confirming` with a do-not-pay warning | No |

`created`, `confirming`, and `paid` are additive response states. `confirming` and `paid` reuse an existing trade and must not call `PaymentTrade::record()`.

### 3. Concurrency contract
- Serialize `preflight -> remote create -> local record` with an order-scoped application lock keyed by `store_id + order_id`.
- The current implementation uses a MySQL advisory lock so no database transaction remains open during the remote WeChat request.
- After acquiring the lock, re-read the order and latest trade before making the decision.
- Lock timeout or acquisition failure is an uncertain state: return `confirming` and do not create a transaction.
- Keep the `PaymentTrade::record()` row-lock guard as defense in depth. A different virtual `out_trade_no` must not replace an `UNPAID` attempt; only a `CLOSED` attempt may be followed by a new row.

### 4. Query convergence contract
- WeChat virtual order status `2` or `3` is paid and must converge the local order idempotently.
- Status `6` is the only definitive terminal-unpaid status.
- Status `0`, status `1`, and any unrecognized status remain pending/unknown and must preserve recovery data.
- Query/network errors must not clear the active transaction or unlock another payment attempt.

### 5. Required regression checks
- Rapid double submit produces one `orderPay` call and one native cashier invocation.
- Native success and page `onShow` recovery for the same trade join one query sequence.
- Pending polling always keeps the same `outTradeNo`; exhaustion remains locked and retains recovery data.
- Backend `confirming`/`paid` responses do not record or open a new transaction.
- Concurrent backend creation is serialized before the remote WeChat create call.
- Explicit cancel carries the cancelled trade identifier to backend verification before a deliberate retry is allowed.

## Scenario: immediate refund after virtual-payment success

### 1. Independent convergence facts
- Local payment success and payment-channel classification are independent facts.
- `xpay_goods_deliver_notify` may prove payment success without carrying `order_type`; therefore a locally paid order can temporarily have `payment_trade.channel_class=UNKNOWN`.
- A local paid flag must not make the cashier skip `query_order` while the bound virtual trade is still unclassified.

### 2. Payment boundary
- The virtual trade-query fast path may return from local state only when the order is paid **and** the bound trade channel is already classified.
- A paid order with an `UNKNOWN` channel must still query WeChat and persist the standard `query_order` evidence so `order_type=0` and `order_type=7` converge immediately.
- This query is convergence only: it must not create a new payment attempt, and repeated pay-success handling remains idempotent.

### 3. Refund boundary
- Before creating a refund row, a paid `wechat_virtual` trade with an `UNKNOWN` channel must perform one synchronous authenticated `query_order` preflight.
- The preflight must run outside the `order -> refunds -> final trade` row-lock transaction.
- Persist the returned order object at the snapshot path consumed by channel classification; retain the complete upstream response separately for audit.
- `order_type=7` routes to the App Store refund flow. Any explicit non-7 integer, including `0`, routes to the existing developer-refund flow.
- Transport errors, API errors, malformed responses, missing `order_type`, binding changes, or non-paid trades remain fail-closed: no refund row, developer refund call, service-risk mutation, or guessed channel is allowed.
- Already classified virtual trades and non-virtual payment methods do not incur the preflight query.

### 4. Defense in depth
- Periodic unknown-channel reconciliation remains a fallback, not a prerequisite for the normal immediate-refund user journey.
- Concurrent cashier, refund, and timer queries merge snapshots under the payment-trade row lock, and channel classification remains monotonic.
