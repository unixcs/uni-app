# Virtual Payment Sandbox Checklist

## Purpose

该清单用于完成 OpenSpec 任务 `1.4` 与 `11.x` 的真实沙箱验收前置校验。

没有通过本清单前，不应把任何沙箱支付、退款、通知链路标记为已验收。

## Required Server Config

服务端必须能从 `store_setting.virtual_payment` 或部署态 `.env` 读取以下字段：

- `enabled`
- `env`
- `offer_id`
- `sandbox_app_key`
- `merchant_id`
- `notify_base_url`
- `message_push_token`
- `message_push_encoding_aes_key`，仅安全模式推送必填

当前代码支持部署态 `.env` 覆写：

- `virtual_payment.enabled`
- `virtual_payment.env`
- `virtual_payment.offer_id`
- `virtual_payment.sandbox_app_key`
- `virtual_payment.production_app_key`
- `virtual_payment.merchant_id`
- `virtual_payment.notify_base_url`
- `virtual_payment.message_push_token`
- `virtual_payment.message_push_encoding_aes_key`

## Required Mini Program Backend Setup

微信小程序后台必须完成以下配置：

- 已开通虚拟支付能力
- `AppID` 与当前小程序一致
- `OfferID` 与服务端配置一致
- 支付道具 `productId` 已创建且价格与商城商品一一对应
- 请求合法域名包含 `https://wx.oiob.cn/`
- 消息推送地址指向 `https://wx.oiob.cn/notice/virtualPayment.php`
- 消息推送模式已确认：
  - 明文模式：必须配置 `message_push_token`
  - 安全模式：必须配置 `message_push_token` 与 `message_push_encoding_aes_key`

## Required Data Preconditions

数据库必须满足以下条件：

- 目标商品是服务商品
- 目标商品已启用虚拟支付 `vp_enabled=1`
- 目标商品已绑定合法 `vp_product_id`
- 目标商品商城售价与 `vp_price_snapshot` 一致
- 测试用户存在 `mp-weixin` 授权记录
- 测试用户 `session_key` 非空

## Known Sandbox Acceptance Path

首期验收只认沙箱闭环：

1. 用户在小程序提交服务订单
2. 收银台仍显示“微信支付”
3. 前端调用 `wx.requestVirtualPayment`
4. 服务端收到 `xpay_goods_deliver_notify`
5. 订单进入 `待联系`
6. 商家开始服务，订单进入 `服务中`
7. 商家完成服务，订单进入 `已完成`
8. 服务前退款验证自动退款
9. 服务中退款验证商家审核
10. 已完成后退款被拒绝
11. 重复通知不重复履约、不重复退款
12. 前端 success 缺失时，通知或查单仍使状态收敛

## Required Evidence

每个验收场景至少保存一份证据到 `runtime/`：

- 支付下单返回参数
- 虚拟支付通知原文
- 查单结果
- 订单详情状态
- 退款详情状态
- 若失败，保留异常日志与响应报文

## Local Readiness Command

真实沙箱验收前先运行：

```bash
cd /opt/yoshop/yoshop2.0
php think virtual-payment:sandbox-check \
  --goods-id 10001 \
  --user-mobile 19900000000 \
  --evidence-dir "/opt/yoshop/runtime/virtual-payment-sandbox-check"
```

若微信后台消息推送使用安全模式，加上 `--require-safe-mode`。

该命令只读取配置和数据库，不创建订单、不调用微信接口。它会检查：

- 虚拟支付运行配置是否已启用并指向沙箱
- 小程序 `app_id/app_secret` 是否存在
- 商品是否为启用虚拟支付的服务商品，且价格快照匹配
- 测试用户是否存在 `MP-WEIXIN` openid 与 `session_key`
- 虚拟支付通知入口文件和路由是否存在
- 最近是否已有匹配的 `wechat_virtual` 交易记录

命令失败时不可勾选 11.1、11.8、11.9；先根据 evidence JSON 补齐缺口。

真实沙箱支付完成后，用真实 `outTradeNo` 追加审计：

```bash
cd /opt/yoshop/yoshop2.0
php think virtual-payment:sandbox-check \
  --goods-id 10001 \
  --user-mobile 19900000000 \
  --out-trade-no "真实 outTradeNo" \
  --expect-paid \
  --expect-pending-contact \
  --expect-duplicate-notify \
  --expect-query-evidence \
  --expect-no-duplicate-refund \
  --expect-provide-goods-idempotent \
  --evidence-dir "/opt/yoshop/runtime/virtual-payment-sandbox-check"
```

审计选项与剩余验收项的关系：

- `--expect-paid` 与 `--expect-pending-contact` 用于证明 11.1 和支付后状态收敛。
- `--expect-duplicate-notify`、`--expect-no-duplicate-refund`、`--expect-provide-goods-idempotent` 用于证明 11.8。
- `--expect-query-evidence` 用于证明 11.9 的主动查单兜底。

## Local Non-Sandbox Regression

真实微信沙箱不可用时，可以先运行本地回归夹具，提前验证通知幂等、查单收敛和履约通知 claim 不变量：

```bash
cd /opt/yoshop/yoshop2.0
php think virtual-payment:local-e2e check \
  --goods-id 10001 \
  --evidence-dir "/opt/yoshop/runtime/virtual-payment-local-e2e"

php think virtual-payment:local-e2e cleanup \
  --evidence-dir "/opt/yoshop/runtime/virtual-payment-local-e2e"
```

该命令不调用微信接口，不能替代 11.1、11.8、11.9 的真实沙箱验收；它只用于在真实联调前发现本地代码级回归。

## Current External Parameters Already Present

仓库文档中已记录但不应继续作为运行时唯一来源：

- `AppID`
- `OfferID`
- 沙箱 `AppKey`
- 现网 `AppKey`
- 商户号
- 若干 `productId`

它们只能作为联调参考，运行时以服务端配置或部署密钥为准。
