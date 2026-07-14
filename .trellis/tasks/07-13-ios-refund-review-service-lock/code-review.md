# Code Review and Defect Closure

Date: 2026-07-14
Reviewer sub-agent: `019f5d6f-a9dd-7fa3-820b-4ea7577bf267` (the only code-review sub-agent for this task)

## Review result received

- P0: 3
- P1: 5
- P2: 1

No second Plan Review was started. The final requested dual-agent adversarial code review is recorded below.

## Verified findings and closure

| Finding | Verification | Closure |
|---|---|---|
| P0-1 malformed `RetCode` cast to zero | Confirmed in Notify and backfill | Added one strict shared success-code predicate accepting only integer `0` / string `"0"`; service finalizer also rejects untrusted payloads. Added malformed-code regression. |
| P0-2 inquiry ignored `lockOrder=false` | Confirmed independently before reviewer returned | `handleInquiry()` now throws on risk-lock write failure so the transaction rolls back and Notify fail-closes. |
| P0-3 provide_goods risk read/claim TOCTOU | Confirmed at dispatch-claim boundary | Added order-first atomic risk check + reuse of the existing trade dispatch claim. Inquiry-first/dispatch-second is covered by a real two-connection test. No new worker/lease/state table was added. A dispatch claimed first is the linearization point; later inquiry is ordered after that existing operation. |
| P1-1 binding failure polluted order timeline | Confirmed | Failed bindings now persist `order_id=0`; regression proves the business order timeline is untouched and zero-order security history remains. |
| P1-2 invalid trade state accepted | Confirmed | Final binding now requires trade SUCCESS/REFUND and order paid; unpaid final trade regression returns fail-closed. |
| P1-3 backfill ignored failed writes | Confirmed | Risk and migrated inquiry writes are checked; false returns throw and roll back. |
| P1-4 order detail permanent loading state | Confirmed | Added `catch/finally`, explicit safe error view, retry, and no action rendering on error. |
| P1-5 refund apply exposed form after authority load failed | Confirmed | Added validated loaded payload, explicit error/retry view, hidden form/actions on failure, and submit guard. |
| P2-1 list projection N+1 | Non-correctness performance debt; partially pre-existing/cross-task | Not expanded in this P0 scope. Latest inquiry is already batch-loaded; broader refund/trade accessor caching is recorded as residual P2 and is not an iOS refund correctness blocker. |

## Post-fix review gate

Based on direct code inspection and regression evidence after fixes:

- Open P0: **0**
- Open P1: **0**
- Open P2: **1** (performance only)

External Apple/WeChat callback and release-runtime checks remain acceptance evidence, not open code-review P0/P1 defects.

## Final dual-agent adversarial review

Reviewers:

- backend/security: `019f5e36-2a26-7b51-979b-6b3a3da8d443`
- cross-layer/acceptance: `019f5e36-5b09-7d70-aa9e-36cdb3481c6d`

Verified and closed findings:

| Finding | Verification | Closure |
|---|---|---|
| P1 safe-mode inquiry returned plaintext business decision | Confirmed against the official WeChat message-push rule: non-empty safe-mode responses must be encrypted | Preserve safe-mode request metadata; encrypt JSON/XML `IosRefundQueryResponse`; executable decrypt assertions added |
| P1 miniapp refund-detail API failure rendered placeholder pseudo-detail | Confirmed in `pages/refund/detail.vue` | Persistent safe error/retry state, stale-detail clearing, hidden normal detail, focused component-contract regression |
| P2 refund-success notify lacked outer entry coverage | Confirmed | Real signed plaintext `xpay_refund_notify` entry contract now proves dispatch, convergence and duplicate idempotency |
| Local P1 non-iOS virtual trade could be misrouted into Apple inquiry | Confirmed because final binding checked virtual platform but not Apple channel classification | Require `isIosAppleVirtualTrade()`; non-iOS fails closed with zero-order audit and no refund/risk mutation |

Post-fix gate:

- Open P0: **0**
- Open P1: **0**
- Open P2: **1** (pre-existing/bounded list-projection performance debt)

No points, cumulative-spend reversal, independent alert table, queue/worker/lease/heartbeat, Android refactor, or other scope expansion was introduced.


## Final adversarial release review

Reviewer: `019f5b64-fbfb-7bd3-a985-14818d2bc2dd`

The release review initially reported `P0=0, P1=3, P2=2`. Every finding was independently verified and closed without expanding product scope:

| Finding | Closure evidence |
|---|---|
| P1 public callback reachability not proven | Existing signed URL-verification probe executed against `https://wx.oiob.cn/notice/virtualPayment.php`: HTTP 200, 1097.53 ms, exact random `echostr` match. |
| P1 trade `10333` conflict not adjudicated | Read-only DB audit proves an unpaid, unbound trade for a cancelled order with no local refund, inquiry, trusted success, or iOS-refund-required evidence; no risk mutation is required. |
| P1 backend rollback could bypass risk guards | Rollback policy changed to forward-fix only; any version predating the irreversible risk guards is explicitly forbidden. |
| P2 R45 contradicted deployment state | R45 now records the closed gate and actual live checksums/HTTP markers. |
| P2 remaining-gate classification was inaccurate | External authorization, internal visual/monitoring acceptance, and closed historical build behavior are separated. |

Post-closure release-review gate:

- Open P0: **0**
- Open P1: **0**
- Open P2: **1** (bounded list-projection performance debt from the code review)

No points, cumulative-spend reversal, independent alert table, worker/queue/lease/heartbeat, Android refactor, or refund-cancellation branch was added.
