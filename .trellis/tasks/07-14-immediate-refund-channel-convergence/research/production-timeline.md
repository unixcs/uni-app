# Production Timeline Evidence

Date: 2026-07-14 (Asia/Shanghai)

## Reproduced transaction

- Order: `10537`
- Payment trade: `10363`
- Out trade number: `335530045843127118`

## Timeline

| Time | Evidence |
|---|---|
| 21:16:43 | `xpay_goods_deliver_notify` processed; order payment success service ran. Callback contained no `order_type`; logged trade still had `channel_class=0`. |
| 21:16:59 | First refund apply inserted provisional refund id `11076`, then the transaction rolled back after the local UNKNOWN-channel guard. No WeChat refund call was made. |
| 21:17:22 | `Order::syncVirtualTradeStates` queried WeChat, received `order_type=0`, and persisted `channel_class=10 (NON_IOS)`. |
| 21:18:27 | Second user apply created refund id `11077` and successfully called developer refund. |
| 21:18:39 | Trusted `xpay_refund_notify` finalized order/refund/trade state. |

## Relevant local evidence paths

- `yoshop2.0/runtime/api/log/202607/14_info.log`
- `yoshop2.0/runtime/api/log/202607/14_sql.log`
- `yoshop2.0/runtime/log/202607/14_info.log`

## Causal chain

```text
pay notify has no order_type
  -> order becomes paid, channel remains UNKNOWN
  -> cashier trade query sees local paid and returns before query_order
  -> refund routing correctly refuses UNKNOWN
  -> periodic query later obtains order_type=0
  -> user retry succeeds
```

The defect is therefore not a WeChat refund API delay. It is a local convergence gap between the paid state and channel classification.
