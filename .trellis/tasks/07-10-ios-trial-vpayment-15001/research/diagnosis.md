# iOS trial 虚拟支付 -15001 诊断摘要

## 核心结论

当前最强根因是：**iOS Apple 支付最低支付金额为 1 元，但当前商品价格仅 0.02 元**，导致客户端虽拿到后端签名参数，但微信/Apple 支付层未真正创建上游订单，后续查单才出现 `数据不存在`。

## 证据链

### 1. 后端已建本地交易，不是完全没下单
- `yoshop2.0/app/api/service/cashier/Payment.php:360-425`
- 运行证据：`yoshop2.0/runtime/api/codex-evidence/virtual-payment-attempt-trace/20260710.jsonl`
- 可见阶段：`virtual_payload_built` → `unifiedorder_result` → `trade_recorded`

### 2. 当前报错文案是系统推断，不是微信原始错误
- `yoshop2.0/app/api/service/cashier/Payment.php:940-950`
- `errcode=268490002` + `errmsg=数据不存在` 被映射为“更像 automator/预览态拦截”

### 3. iOS 当前并非 xpay 沙箱
- 同次支付 payload：`env=0`
- 小程序环境：`envVersion=trial`
- 结论：`trial` 是小程序体验版，不等于 `env=1` 沙箱

### 4. 微信远端确实查不到订单
- `runtime/virtual-payment-sandbox-check/sandbox-check-20260710220545-452926.json`
- `runtime/virtual-payment-sandbox-check/sandbox-check-20260710220608-682787.json`
- 两次结果都为：`errcode=268490002`, `errmsg=数据不存在`

### 5. 商品价格与 iOS 官方规则冲突
- 商品：`goods_id=10008`
- 商品价：`0.02` 元（`goods_price=2` 分）
- 商品配置生成逻辑允许 `<1 元`：
  - `yoshop2.0/app/store/model/Goods.php:323-340`
  - `yoshop2.0-store/src/views/goods/modules/virtualPayment.js:16-48`
- 但 iOS 官方文档要求：
  - iOS 15+
  - 微信 8.0.68+
  - 最低支付金额 1 元
  - Apple 支付不支持沙箱，仅支持现网

## 官方来源
- https://developers.weixin.qq.com/miniprogram/dev/platform-capabilities/business-capabilities/virtual-payment/ios.html
- https://developers.weixin.qq.com/miniprogram/dev/api/payment/wx.requestVirtualPayment.html

## 后续建议
1. 先做业务决策：iOS 是否接受 `>= 1 元`
2. 若进入修复：
   - 前端支付前增加 iOS 最低 1 元拦截
   - 后端 / 管理端补充同规则校验或运营提示
   - 调整 `remote query 数据不存在` 的用户提示，避免过度归因 automator


## 新增问题：iOS 1 元支付成功但退款失败

### 新结论

当商品改到 `1 元` 后，iOS 支付已经能成功跳转 **App Store** 并完成支付；但退款时报 `IOS订单不支持开发者发起退款`，这与微信官方 iOS 虚拟支付规则**完全同向**：

- iOS Apple 支付**不支持开发者主动发起退款**；
- 用户应前往 **App Store** 申请退款；
- Apple 会向开发者发起退款问询；
- 开发者只能响应问询，最终退款决定权仍在 Apple。

因此，这个新问题当前更像是：**产品退款流程与 iOS 平台规则不兼容**，而不是“退款代码偶发坏了”。

### 代码证据链

#### 1. 用户退款入口会触发自动退款
- `yoshop2.0/app/api/model/OrderRefund.php:321-341`
- 当服务订单处于自动退款阶段时，用户提交退款后会直接调用后台 `completeAutoRefund()`。

#### 2. 商家“服务前退款”也会走同一自动退款链路
- `yoshop2.0/app/store/model/Order.php:179-209`
- 会先创建退款单，再调用 `completeAutoRefund('商家在服务开始前直接退款')`。

#### 3. 自动退款底层会直接调用虚拟支付退款接口
- `yoshop2.0/app/store/model/OrderRefund.php:302-316`
- `yoshop2.0/app/common/service/order/Refund.php:112-199`
- 只要交易平台是 `wechat_virtual`，就会调用 `/xpay/refund_order`。
- 若微信返回非 0 `errcode`，系统会直接抛出 `errmsg`。

#### 4. 当前通知服务未见 iOS 退款问询事件处理
- `yoshop2.0/app/api/service/Notify.php:41-43,195-206`
- 当前仅处理 `xpay_goods_deliver_notify` 与 `xpay_refund_notify`。
- 仓库内未检索到 `xpay_subscribe_ios_refund_query_notify` / `WxaVirtualPayIosRefundQueryNotifyEvent` 的处理代码。

### 第一性原理解释

iOS Apple 支付的资金扣款发生在 Apple 支付体系内。支付成功只代表“开发者完成了销售”，**不代表开发者保有主动退款权**。

所以当前系统中：
- 小程序“退款”按钮；
- 后台“服务前退款”按钮；

虽然在业务语义上看起来不同，但技术语义上都属于“开发者主动发起退款”。对 iOS Apple 订单而言，这两个入口本来就会一起失效。

### 官方参考
- iOS 端接入 / 用户退款：
  - https://developers.weixin.qq.com/miniprogram/dev/platform-capabilities/business-capabilities/virtual-payment/ios.html#%E5%85%AD%E3%80%81%E7%94%A8%E6%88%B7%E9%80%80%E6%AC%BE

### 后续修复方向（尚未实施）
1. 识别 `wechat_virtual` 且来源为 iOS Apple 支付的订单；
2. 用户端隐藏/禁用退款入口，改成“请前往 App Store 申请退款”；
3. 商家后台隐藏/禁用“服务前退款”按钮，改为平台限制说明；
4. 视业务需要补充 `xpay_subscribe_ios_refund_query_notify` 问询处理与状态收口。


## 新增证据：iOS 1 元 Apple 实单已成功支付

### 运行证据
- 文件：`yoshop2.0/runtime/api/codex-evidence/virtual-payment-attempt-trace/20260710.jsonl`
- 成功样本：
  - `order_id=10513`
  - `order_no=334098259006387503`
  - `out_trade_no=334098380149377916`
  - `goods_price=100`（1 元）
  - `pay_price_fen=100`
  - `host_env=trial / host=ios / scene=1007 / wx=8.0.75 / sdk=3.16.2`
- 关键阶段：`unifiedorder_result` 成功、`trade_recorded` 成功。

### 数据库证据
- `yoshop_payment_trade.trade_id=10334`
  - `trade_state=20`
  - `query_order.result.order.status=3`
  - `query_order.result.order.order_type=7`
  - `paid_fee=100`
  - `paid_time` 已存在
- `yoshop_order.order_id=10513`
  - `pay_status=20`
  - `trade_id=10334`
  - `pay_method=wechat`
  - `pay_time=2026-07-10 22:27:57`

### 推论
- iOS 当前环境并不是“完全无法支付”，而是**低于 1 元的 Apple 单无法成立**。
- 一旦金额满足 Apple 规则（`>=1 元`），当前生产链路可以成功创建远端订单并支付完成。

## 新增发现：`query_order.status=3` 也必须视为已支付

### 现象
使用真实订单 `out_trade_no=334098380149377916` 运行：

```bash
php think virtual-payment:sandbox-check   --goods-id=10010   --out-trade-no=334098380149377916   --expect-paid   --expect-query-evidence   --expect-no-duplicate-refund   --probe-remote-query
```

远端返回：
- `errcode=0`
- `status=3`

### 结论
- 在当前业务语义里：
  - `status=2` = 已支付
  - `status=3` = **已支付，但待下游履约/发货/消耗**
- 因此诊断命令和 live watch 工具都必须把 `[2,3]` 视为 paid-like 状态，不能把 `3` 错判成失败。

## 新增缺陷闭环：iOS 退款展示状态误判

### 问题
`PaymentTrade::buildVirtualRefundProjection()` 之前只要收到一个合成的 refund 数组，就可能把“尚无本地退款单”的 iOS Apple 订单误显示成：
- `等待 App Store 退款处理`

但这违反平台事实：**用户还没有提交任何本地退款跟踪记录时，系统只能引导去 App Store 申请退款**。

### 修复原则
- 只有两类情况才显示 `waiting_app_store_refund`：
  1. `payload_snapshot.virtual_refund.status == waiting_ios_apple_refund`
  2. 真实存在本地退款单（`order_refund_id > 0`）
- 否则必须回到：
  - `display_state=app_store_guided`
  - `display_state_text=请前往 App Store 申请退款`

### 复验结果
- 使用 `order_id=10513` 复验：
  - API 侧 `refund_info.display_state_text=请前往 App Store 申请退款`
  - 商家侧 `backend_action_flags.refund_display_state_text=请前往 App Store 申请退款`
- 说明：无本地退款单时的状态投影已经回归正确。
