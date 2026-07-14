# iOS 体验版虚拟支付报错排查

## Goal

从第一性原理定位「Android 正常、iOS 小程序体验版支付失败」与「iOS Apple 支付退款失败」的最可能根因，给出可执行的产品/技术处置方向，并明确后续实现范围。

## Background

- 现象一：Android 手机下单和虚拟支付正常；iOS 手机打开小程序**体验版**后支付下单失败。
- 当前展示给用户的错误文案为：
  - `微信侧尚未创建这笔虚拟支付订单... (错误码:-15001，交易号:334085376850339730，运行环境:trial，宿主:ios，场景:1007，微信:8.0.75，SDK:3.16.2)`
- 现象二：将 iOS 商品价格调到 `1 元` 后，支付可以拉起 App Store 并支付成功，但：
  1. 用户在小程序内申请退款失败；
  2. 商家后台点击「服务前退款」也失败；
  3. 报错为：`IOS订单不支持开发者发起退款`。
- 现阶段目标不是先套经验结论，而是拆解事实链路：
  1. 后端是否成功生成虚拟支付签名参数；
  2. 前端是否真正调用到 `wx.requestVirtualPayment`；
  3. 微信 / Apple 支付层是否真正创建上游订单；
  4. iOS 路径是否有与 Android 不同的硬性约束；
  5. 支付成功后，退款控制权是否仍然属于开发者。

## Confirmed Facts

### A. 关于 iOS trial 支付失败 `-15001`

1. **当前报错文案不是微信原始根因，而是后端查单失败后的推断性提示。**
   - `yoshop2.0/app/api/service/cashier/Payment.php:940-950`
   - 当远端查单返回 `errcode=268490002` 且 `errmsg` 含“数据不存在”时，系统映射成“更像 automator/预览态拦截”。

2. **本次 iOS 失败发生在小程序 `trial` 环境，但虚拟支付 `env=0`，即现网支付参数，不是沙箱。**
   - 证据：`yoshop2.0/runtime/api/codex-evidence/virtual-payment-attempt-trace/20260710.jsonl`
   - 失败链路中的客户端上下文：`envVersion=trial`、`platform=ios`、`scene=1007`、`wechatVersion=8.0.75`、`sdkVersion=3.16.2`、`system=iOS 26.5`
   - 同次支付参数：`out_trade_no=334085376850339730`，`env=0`，`product_id=vip002`，`goods_price=2` 分。

3. **后端已成功生成虚拟支付 payload，并已记录本地交易单。**
   - 证据阶段：`virtual_payload_built` → `unifiedorder_result` → `trade_recorded`
   - 代码锚点：`yoshop2.0/app/api/service/cashier/Payment.php:360-425`
   - 推论：签名生成、session_key、offerId、productId、订单落库这条链路基本已走通。

4. **微信远端查单明确返回“数据不存在”，说明微信/Apple 支付侧并未创建这笔上游订单。**
   - 证据文件：
     - `runtime/virtual-payment-sandbox-check/sandbox-check-20260710220545-452926.json`
     - `runtime/virtual-payment-sandbox-check/sandbox-check-20260710220608-682787.json`
   - 两次 outTradeNo（`334085376850339730`、`334085419399728865`）的远端探测结果均为：
     - `errcode=268490002`
     - `errmsg=数据不存在`
   - 推论：问题更靠近 **客户端拉起支付前后 / 微信支付层受理条件**，而不是“后端完全没建单”。

5. **iOS 官方规则与当前商品价格存在直接冲突。**
   - 当前商品：`goods_id=10008`，价格 `0.02` 元，`vp_product_id=vip002`
   - 代码与配置允许该价格：
     - `yoshop2.0/app/store/model/Goods.php:323-340`
     - `yoshop2.0-store/src/views/goods/modules/virtualPayment.js:16-48`
   - 现有规则只要求：
     - 金额必须 > 0
     - **1 元及以上**时必须是整数
   - 但微信官方 iOS 文档写明：
     - iOS Apple 支付要求 `iOS 15+`
     - 微信 `8.0.68+`
     - **最低支付金额为 1 元**
     - **Apple 支付不支持沙箱，仅支持现网环境**
   - 官方来源：
     - https://developers.weixin.qq.com/miniprogram/dev/platform-capabilities/business-capabilities/virtual-payment/ios.html
     - https://developers.weixin.qq.com/miniprogram/dev/api/payment/wx.requestVirtualPayment.html

6. **当前前端只校验了 iOS 版本 / 微信版本 / 沙箱限制，没有校验 iOS 最低 1 元。**
   - `yoshop2.0-uniapp/core/payment/wechat.js:137-160`
   - 已有能力校验：SDK 版本、iOS 主版本、微信版本、iOS 不支持沙箱。
   - 未见对 `goods_price < 100` 且 `platform=ios` 的前置拦截。

### B. 关于 iOS 1 元支付成功但退款失败

1. **当前前后端退款入口原始设计，都是“开发者主动退款”模型。**
   - 用户侧退款申请：`yoshop2.0/app/api/model/OrderRefund.php:286-347`
   - 商家侧服务前退款：`yoshop2.0/app/store/model/Order.php:181-209`
   - 当前服务前退款确认文案仍然是：`该操作会按原路退款并关闭当前服务单。`
   - 商家详情页仍直接展示按钮：`yoshop2.0-store/src/views/order/Detail.vue`

2. **用户侧前端仍然把 iOS Apple 订单当成可直接申请开发者退款。**
   - 订单详情页只要 `can_apply_refund` 为真，就展示「退款」按钮：
     - `yoshop2.0-uniapp/pages/order/detail.vue:94-107`
     - `yoshop2.0-uniapp/pages/order/detail.vue:61`
   - 退款申请页文案仍为通用售后申请：
     - `提交退款申请`
     - `请详细填写退款原因，建议您先与服务方沟通`
     - 文件：`yoshop2.0-uniapp/pages/refund/apply.vue`
   - 这意味着当前用户体验仍在暗示“开发者可直接处理退款”。

3. **商家后台同样没有把 iOS Apple 订单和普通退款入口区分开。**
   - 详情页仍展示「服务前退款」按钮，且确认文案是“按原路退款”：
     - `yoshop2.0-store/src/views/order/Detail.vue:41-44`
     - `yoshop2.0-store/src/views/order/Detail.vue:414-420`
   - 当前后台 `backend_action_flags` 仅暴露：
     - `can_start_service`
     - `can_complete_service`
     - `can_refund_before_service`
     - `has_active_refund`
     - `active_refund_id`
     - `can_audit_refund`
   - 文件：`yoshop2.0/app/store/model/Order.php:628-638`
   - **未暴露“这是 iOS Apple 退款订单，应走 App Store 退款链路”的显式前端标志。**

4. **API 用户端也尚未暴露 iOS Apple 退款识别信息，前端难以做正确文案/入口分流。**
   - `app/api/model/Order.php` 当前追加字段包含：
     - `action_flags`
     - `refund_info`
     - `pending_payment_info`
   - 但未见类似 `ios_apple_refund_required`、`refund_entry_mode`、`refund_guidance` 之类字段。
   - `refund_info` 当前只有通用投影：
     - `退款审核中`
     - `退款处理中`
     - `已退款`
   - 文件：
     - `yoshop2.0/app/api/model/Order.php:640-659`
     - `yoshop2.0/app/api/model/OrderRefund.php:36-93`

5. **后端退款链路已开始支持 Apple 官方模式，但产品表面尚未对齐。**
   - 已接入 Apple 退款问询回调：`xpay_subscribe_ios_refund_query_notify`
   - iOS Apple 虚拟支付订单不再主动调用 `/xpay/refund_order`，而是进入：
     - `waiting_ios_apple_refund`
   - 文件：
     - `yoshop2.0/app/api/service/Notify.php`
     - `yoshop2.0/app/common/service/order/Refund.php`
   - 这解决的是**后端收口正确性**，但尚未解决：
     - 用户入口误导
     - 商家入口误导
     - 前端状态文案误导

6. **官方规则已明确：iOS Apple 支付不支持开发者主动退款。**
   - 用户应在 **App Store** 发起退款；
   - Apple 会向开发者发起退款问询；
   - 开发者只能响应问询，最终是否退款仍由 Apple 决定；
   - 相关事件：`xpay_subscribe_ios_refund_query_notify`。
   - 官方来源：
     - https://developers.weixin.qq.com/miniprogram/dev/platform-capabilities/business-capabilities/virtual-payment/ios.html#%E5%85%AD%E3%80%81%E7%94%A8%E6%88%B7%E9%80%80%E6%AC%BE

## First-Principles Diagnosis

### 问题重述

为什么同一套小程序虚拟支付与退款流程，Android 能正常，而 iOS 会先在低价商品上支付失败、在 1 元商品上支付成功但退款失败？

### 基础事实

- 后端已能为 iOS 构造现网虚拟支付参数并记录本地交易。
- iOS 低价商品 `0.02 元` 时，微信 / Apple 支付侧并未真正创建上游订单。
- iOS Apple 支付路径有独立硬约束，其中之一是 **最低支付金额 1 元**。
- 将价格调到 `1 元` 后，支付可成功跳转 App Store 并扣费，说明支付创建链路本身可走通。
- 但 iOS Apple 支付成功后，退款控制权不再和 Android 对称。
- 官方规则明确：**开发者不能主动为 iOS Apple 订单调用退款接口**，只能等待用户在 App Store 申请退款，并通过问询/通知链路参与。
- 当前代码虽然已经开始支持后端回调收口，但前端/商家端仍把它展示成“可直接退款”的产品。

### 当前最强结论

1. **问题一（iOS 下单 `-15001`）的根因**：价格 `0.02 元` 违反 iOS 最低 `1 元` 规则，导致支付未真正创建上游订单。
2. **问题二（iOS 1 元支付成功但退款失败）的根因**：当前产品入口仍按“开发者主动退款”思维暴露给用户和商家，而 iOS Apple 支付订单根本不允许走该模式；当前报错更像是**平台规则命中**，不是偶发代码异常。
3. **现阶段真正剩余的问题已经从“底层退款能否调用”转为“产品入口、文案、状态机是否与 Apple 退款事实对齐”。**

## Requirements

除原有支付金额策略外，当前需要补齐的需求已经聚焦到 **iOS 退款产品策略**：

1. 明确 iOS Apple 支付订单的退款入口策略：
   - 是否保留本地退款单用于客服追踪；
   - 是否允许用户继续在小程序里“提交退款申请”；
   - 是否允许商家继续看到“服务前退款”按钮。

2. 如果保留本地退款单，需定义它的真实语义：
   - 是“向开发者申请退款”；
   - 还是“登记退款诉求 + 引导用户到 App Store 申请退款 + 等待 Apple 决策”。

3. 需要决定前端与后台是否新增平台感知字段与文案：
   - `refund_entry_mode`
   - `ios_apple_refund_required`
   - `refund_guidance`
   - `waiting_ios_apple_refund` 的用户/商家可读文案

4. 需要决定退款状态展示目标：
   - 用户是否应看到“请前往 App Store 申请退款”；
   - 商家是否应看到“等待 Apple 退款问询/结果通知”；
   - 是否需要把泛化文案“退款处理中”改为平台感知文案。

## Acceptance Criteria

- [ ] 能区分“支付创建失败”和“支付成功但退款权限不在开发者侧”两类问题。
- [ ] 能解释为什么 iOS 用户侧退款与商家侧服务前退款会同时失败。
- [ ] 能确认当前报错更接近平台规则命中，而非随机代码异常。
- [ ] 能明确当前剩余工作的主问题已转向 **产品/UX 对齐**，而非继续深挖底层退款 API。
- [ ] 在进入下一轮实现前，拿到业务方对 **iOS 退款入口策略** 的明确选择。

## Open Question

在 iOS Apple 支付订单上，退款入口策略要选哪一种？

1. **保留本地退款单，但语义改为“登记诉求 + 引导 App Store 退款 + 等待 Apple 决策”**；
2. **彻底隐藏 / 禁用用户退款入口与商家“服务前退款”入口，只展示说明文案和状态**；
3. **继续保留现状入口**（已知会制造误导，不推荐）。
