## Why

当前虚拟支付功能已经“能跑起来”，但还没有真正“收口”。最近实机测试已经暴露出两类典型问题：一类是支付成功了但本地订单没有认账，另一类是退款申请成功了但订单/服务单状态没有同步收回来；再加上之前遗留的 `out_trade_no -> order_id` 兼容问题，说明现在的问题不是单点 bug，而是整条支付-查单-通知-退款-订单状态链路还不够一致。

现在如果继续只补零散点位，后面很容易继续冒出同类问题。需要把这批问题合并成一个新的“收口任务”，从第一性原理重新检查：谁是业务真源、谁负责认账、谁负责补偿、哪些入口要兼容、哪些页面和后台状态必须保持一致。

## What Changes

- 对微信虚拟支付的支付成功收敛链路做统一收口：前端 success、主动查单、异步通知、补偿任务、重复支付场景都要以同一套认账规则落地。
- 对微信虚拟支付的退款收敛链路做统一收口：退款申请、商家审核、平台退款处理中、退款完成、订单取消、页面状态展示必须一致。
- 修补从普通微信支付切到虚拟支付后遗漏的入口兼容，包括支付相关外部入口对 `out_trade_no -> order_id` 的识别与映射。
- 统一小程序订单页、订单详情页、退款页、后台订单详情、后台动作按钮的状态判断，避免“已支付但还显示去支付”“退款中了却仍按普通处理中展示”等错位。
- 补齐虚拟支付全链路验收与碰撞测试，按墨菲定律覆盖：多次点击支付、success 先到/通知后到、查单过早、退款半完成、后台操作与退款冲突、体验版/开发版差异、重复通知、旧订单入口跳转等场景。
- 修正当前项目里的相关巡检/诊断脚本，让服务退款、虚拟支付交易、补偿状态能被正确识别，不再用旧普通支付假设误判现状。

## Capabilities

### New Capabilities
- `virtual-payment-convergence`: 规范虚拟支付在下单、支付成功、主动查单、异步通知、补偿任务、退款完成之间的最终认账与收口规则。
- `service-order-state-consistency`: 规范服务订单在小程序端、退款页、后台订单详情和支付相关入口中的状态文案、按钮、兼容映射与可操作性一致性。

### Modified Capabilities
- 无。本次先把收口规则作为新的能力规范沉淀，避免在当前仓库尚未归档主 specs 的情况下制造悬空修改。

## Impact

- 后端支付链路：`app/api/service/cashier/Payment.php`、`app/api/service/Notify.php`、`app/api/model/PaymentTrade.php`、`app/common/model/PaymentTrade.php`
- 订单/退款状态链路：`app/api/model/Order.php`、`app/common/model/Order.php`、`app/common/service/order/PaySuccess.php`、`app/common/service/order/Refund.php`、`app/store/model/Order.php`
- 小程序页面链路：`yoshop2.0-uniapp/pages/checkout/cashier/index.vue`、`pages/order/index.vue`、`pages/order/detail.vue`、退款相关页面与支付核心模块
- 定时补偿与诊断：`app/timer/service/Order.php`、巡检/自动化脚本、实机证据采集脚本
- 运行与验收方式：开发版、体验版、Android 真机、微信异步通知、主动查单、后台审核退款、旧入口跳转兼容
