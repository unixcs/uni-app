# Technical Design: 小程序支付成功后重复拉起收银台修复

## 1. Problem Restatement

用户只表达了一次支付意图，但当前实现把“页面重新显示”“原生支付成功回调”“后端状态暂未收口”作为彼此独立的事件处理，导致首笔交易结果未知时重新开放支付入口。修复目标不是隐藏微信提示，而是建立一个单调、幂等的支付收口状态机：**同一订单的活动交易未到明确终态前，不产生第二笔交易。**

## 2. Current Failure Sequence

```text
用户点击确认支付
  -> cashier/orderPay 创建 outTradeNo=A
  -> wx.requestVirtualPayment(A)
  -> onSubmitCallback 未返回支付 Promise，disabled 很快解除
  -> 支付层关闭，页面 onShow
      -> getCashierInfo
      -> performance 消费 tempUnifyData(A)
      -> onTradeQuery(A)
  -> 原生 success 也调用 onTradeQuery(A)
      -> 因 isTradeQuerying=true 直接丢弃
  -> 首次查询恰好返回 CREATED/PAYING, isPending=true
      -> 当前 onTradeQuery 把 pending 送入 onPayFail
      -> 页面仍停在收银台且允许再次支付
  -> 再次 orderPay 创建 outTradeNo=B
  -> wx.requestVirtualPayment(B)
  -> 微信检测到近期同金额已支付，展示重复支付风险提示
```

## 3. Architecture Boundaries

### Frontend

Files:
- `yoshop2.0-uniapp/pages/checkout/cashier/index.vue`
- `yoshop2.0-uniapp/core/payment/wechat.js`（仅在需要补充取消/恢复元数据时修改）
- `yoshop2.0-uniapp/scripts/tests/payment-convergence-contract.cjs`（新增）

Responsibilities:
- 接收一次用户意图。
- 保存当前活动交易号。
- 合并 `onShow` 恢复和原生 success 的收口路径。
- 有界轮询后端权威状态。
- 在明确终态之前阻止第二次 `orderPay`。
- 幂等提示与导航。

### Backend

Files:
- `yoshop2.0/app/api/service/cashier/Payment.php`
- `yoshop2.0/app/api/model/PaymentTrade.php`（增加安全的最新尝试读取/终态更新能力时修改）
- `yoshop2.0/scripts/tests/virtual-payment-repeat-guard-contract.php`（新增）

Responsibilities:
- 新建虚拟支付交易前检查同订单最近一次虚拟交易。
- 通过微信主动查单识别 paid / pending / terminal-unpaid。
- paid 时调用现有 `OrderPaySuccessService` 幂等收口。
- pending/unknown 时返回“继续确认旧交易”，不创建新交易号。
- 只有明确终态未支付时才允许创建新交易。

## 4. Frontend State Machine

Use a small explicit state field rather than several unrelated booleans:

```text
idle
  -> creating       用户明确点击，正在调用 orderPay
  -> cashier_open   已获得支付参数并调用微信收银台
  -> confirming     微信 success / 页面恢复 / 后端要求继续查旧交易
  -> success        后端确认已支付；终态
  -> cancelled      用户明确取消；可回到 idle
  -> failed         明确不可恢复失败；可回到 idle
```

Derived lock:

```js
isPaymentLocked = ['creating', 'cashier_open', 'confirming', 'success'].includes(paymentPhase)
```

Existing `disabled` can remain as template compatibility but must reflect the state machine, or be replaced by a computed property. It must not be reset by an outer `finally` before the payment/convergence Promise completes.

### Activity identity

Maintain:
- `activeOutTradeNo`
- `activePaymentMethod`
- `paymentPhase`
- `tradeQueryPromise`
- `successHandled`
- `pageShowRecoveryPending` only if needed

The transaction number is the identity key. A recovery request for another order/trade must not mutate the active state.

## 5. Unified Convergence Flow

Introduce one method with Promise semantics, conceptually:

```js
confirmPaymentResult({ outTradeNo, method, source, maxAttempts })
```

Rules:
1. Missing transaction number is a definite client error.
2. If the same transaction already has an active convergence Promise, return that Promise.
3. Query `cashier/tradeQuery`.
4. `isPay=true` -> `finishPaymentSuccess()`.
5. `isPending=true` -> delay then retry within the bounded attempt count.
6. If query says not paid but order refresh now reports `pay_status=SUCCESS`, also finish success.
7. Explicit terminal-unpaid/closed -> clear recovery state and expose retry.
8. Network/unknown after the budget -> retain `tempUnifyData`, keep a safe “result confirming” state, and do not automatically launch a new cashier.

Initial polling budget:
- immediate query plus 5 retries;
- delays approximately `400, 800, 1200, 1600, 2000ms`;
- total foreground wait about 6 seconds.

This is long enough for common notify/query lag but bounded so the page does not spin indefinitely. Recovery on a later `onShow` can start a new bounded polling window for the same transaction.

## 6. Lifecycle Race Handling

### `onShow`

- Initial page display loads cashier info normally.
- If `paymentPhase` is `cashier_open`, the lifecycle event is only a signal that the native cashier may have returned; it must not independently consume and discard the stored transaction while another result path is active.
- If a stored `tempUnifyData` exists and there is no active native payment Promise (cold recovery, process restart, callback loss), set `activeOutTradeNo` and call the unified convergence method.
- If order info already reports paid, call the idempotent success finalizer directly.

### Native success

- Set phase to `confirming`.
- Call and return the same unified convergence method.
- If `onShow` already started it for the same transaction, join the existing Promise.

### Native cancel

- Clear the transaction recovery marker for this attempt.
- Set phase to `cancelled`, then `idle` after feedback.
- Do not query and do not navigate.

## 7. Success Idempotency

Create one finalizer:

```js
finishPaymentSuccess(message)
```

It returns immediately if `successHandled` is already true. First execution:
- sets `successHandled=true` and phase `success`;
- clears temporary/recovery storage;
- emits `syncRefresh` once;
- displays one success toast;
- schedules one navigation.

All paid paths (`orderInfo`, trade query, backend preflight paid response, balance payment) use this finalizer.

## 8. Backend Repeat-Payment Guard

### Preflight point

Run after the order is loaded and basic order eligibility is checked, but before `virtualUnifiedorder()` generates a new `outTradeNo`.

### Latest attempt resolution

For an order requiring WeChat virtual payment:
1. Load the latest virtual trade scoped to the same order/store/user.
2. No trade -> create a new one.
3. Local trade `SUCCESS/REFUND` or order already paid -> return `state=paid`.
4. Local trade `CLOSED` -> create a new one.
5. Local trade `UNPAID` -> query the existing `outTradeNo` through the existing virtual query path.

### Query mapping

```text
paid / paid-pending-delivery
  -> existing orderPaySuccess
  -> payment.state = paid

created / paying
  -> payment.state = confirming
  -> include existing outTradeNo
  -> no new trade

closed or explicit terminal-unpaid
  -> mark local trade CLOSED when safe
  -> create a new outTradeNo

remote/network/config error or unknown status
  -> payment.state = confirming
  -> include existing outTradeNo and safe message
  -> no new trade
```

The fail-closed choice is deliberate: when the system cannot prove the old attempt is unpaid, it must not authorize a second charge.

### API response extension

Keep the existing top-level response and extend `data.payment`:

```json
{
  "provider": "virtual",
  "platform": "wechat_virtual",
  "state": "created | confirming | paid",
  "outTradeNo": "...",
  "message": "..."
}
```

Compatibility:
- Existing newly-created responses default to `state=created`.
- Frontend treats missing `state` as `created` for older backend compatibility.
- `confirming` does not contain payment signatures and must never call `wx.requestVirtualPayment`.
- `paid` directly enters the success finalizer.

## 9. Data and Concurrency Considerations

- `PaymentTrade::record()` already preserves separate virtual attempts and locks the latest row. The new guard must not remove this historical safety.
- There remains a narrow concurrent-request race if two `orderPay` HTTP requests arrive before either records a trade. Serialize the preflight/create section with a short cache/DB lock keyed by `store_id + order_id + virtual-payment-create`.
- Recommended implementation uses the project’s existing cache lock pattern if present; otherwise use a DB transaction with an order-row lock around re-read, preflight decision, and trade recording. Do not hold a DB transaction open across a remote WeChat HTTP request.
- Therefore use a two-stage guard:
  1. short order-scoped application lock;
  2. query old attempt outside DB row transaction but while the application lock is held with a strict timeout;
  3. re-read order/latest trade before creating.
- Lock acquisition failure returns `state=confirming`, never creates a second attempt.

## 10. Error Semantics

| Condition | Frontend result | May create new payment? |
|---|---|---|
| Native success + backend paid | success + navigate | No |
| Native success + status 0/1 | confirming + poll | No |
| Lost callback + onShow recovery | confirming + poll same trade | No |
| User explicit cancel | cancelled | Yes, on next explicit click |
| Remote status closed | idle/failed with retry prompt | Yes |
| Query timeout/network unknown | confirming/recovery | No |
| Existing trade already paid | success + navigate | No |
| Double tap / concurrent submit | join/ignore active attempt | No |

## 11. Compatibility and Rollback

- Changes are additive to `data.payment`; old clients ignore the new field.
- New clients accept missing `state` as old behavior.
- Backend guard is scoped to `wechat_virtual`; ordinary WeChat, Alipay, balance, recharge, H5 and APP payment paths remain unchanged.
- Rollback order, if needed: frontend state machine can be rolled back independently only if backend retains `created` compatibility; backend guard can be disabled by removing the preflight call without altering stored trade history.

## 12. Observability

Reuse `traceVirtualPaymentAttempt()` and add stages:
- `repeat_guard_entry`
- `repeat_guard_no_existing_trade`
- `repeat_guard_existing_paid`
- `repeat_guard_existing_confirming`
- `repeat_guard_existing_closed`
- `repeat_guard_query_error`
- `repeat_guard_new_attempt_allowed`

Logs include order ID, existing trade ID/out-trade number, decision and remote status, but never `appKey`, `session_key`, `paySig` or `signature`.
