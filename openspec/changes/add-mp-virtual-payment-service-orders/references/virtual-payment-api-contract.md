# Virtual Payment API Contract

## Scope

本文件固化当前实现依赖的三类官方协议：

- 前端拉起支付参数
- 服务端主动查单 / 退款 / 履约通知接口
- 微信虚拟支付异步通知

## 1. Frontend Request Contract

前端调用 `wx.requestVirtualPayment` 时，服务端应返回最小字段：

- `mode`
- `paySig`
- `signature`
- `signData`
- `platform=wechat_virtual`
- `outTradeNo`

其中 `signData` 当前实现至少包含：

- `offerId`
- `env`
- `currencyType`
- `buyQuantity`
- `productId`
- `goodsPrice`
- `outTradeNo`
- `attach`

## 2. Server Query Contract

服务端主动查单使用：

- `POST /xpay/query_order`

请求最小字段：

- `openid`
- `env`
- `order_id`

服务端使用：

- `access_token`
- `pay_sig`

返回结果至少需要能判断：

- 平台订单是否已支付成功
- 平台交易号
- 平台返回码与错误信息

请求样例：

```json
{
  "openid": "o-user",
  "env": 1,
  "order_id": "VP202607010001"
}
```

## 3. Refund Contract

服务端退款使用：

- `POST /xpay/refund_order`

请求最小字段应覆盖：

- `openid`
- `order_id`
- `refund_order_id`
- `left_fee`
- `refund_fee`
- `refund_reason`
- `req_from`
- `env`

返回结果至少需要能判断：

- 退款受理是否成功
- 平台退款任务标识
- 失败码与失败信息

请求样例：

```json
{
  "openid": "o-user",
  "order_id": "VP202607010001",
  "refund_order_id": "VPREF202607010001",
  "left_fee": 990,
  "refund_fee": 1,
  "refund_reason": "3",
  "req_from": "3",
  "env": 1
}
```

## 4. Provide-Goods Contract

服务完成后，服务端通知已履约：

- `POST /xpay/notify_provide_goods`

请求至少应覆盖：

- `out_trade_no`
- `status`

当前实现将重复发送控制在交易表快照的 `provide_goods` 节点内。

请求样例：

```json
{
  "out_trade_no": "VP202607010001",
  "status": 1
}
```

## 5. Payment Notify Contract

当前实现按微信小程序虚拟支付 `xpay_goods_deliver_notify` 处理，期望字段位于顶层：

- `ToUserName`
- `FromUserName`
- `CreateTime`
- `MsgType`
- `Event`
- `OpenId`
- `OutTradeNo`
- `Env`
- `WeChatPayInfo`
- `GoodsInfo`
- `TeamInfo`

其中至少使用：

- `Event`
- `OutTradeNo`
- `WeChatPayInfo.TransactionId`

样例：

```json
{
  "ToUserName": "gh_xxx",
  "FromUserName": "wxpay",
  "CreateTime": 1780000000,
  "MsgType": "event",
  "Event": "xpay_goods_deliver_notify",
  "OpenId": "o-user",
  "OutTradeNo": "VP202607010001",
  "Env": 1,
  "WeChatPayInfo": {
    "MchOrderNo": "VP202607010001",
    "TransactionId": "4200000000001",
    "PaidTime": 1780000000
  },
  "GoodsInfo": {
    "ProductId": "vip0",
    "Quantity": 1,
    "OrigPrice": 1,
    "ActualPrice": 1,
    "Attach": "{\"order_id\":123}"
  }
}
```

## 6. Refund Notify Contract

当前实现按微信小程序虚拟支付 `xpay_refund_notify` 处理，期望字段位于顶层：

- `ToUserName`
- `FromUserName`
- `CreateTime`
- `MsgType`
- `Event`
- `OpenId`
- `MchOrderId`
- `RetCode`
- `RetMsg`

其中至少使用：

- `MchOrderId`
- `RetCode`
- `RetMsg`

样例：

```json
{
  "ToUserName": "gh_xxx",
  "FromUserName": "wxpay",
  "CreateTime": 1780000001,
  "MsgType": "event",
  "Event": "xpay_refund_notify",
  "OpenId": "o-user",
  "MchOrderId": "VP202607010001",
  "RetCode": 0,
  "RetMsg": "success"
}
```

## 7. Notify Source Verification Contract

消息推送来源校验按小程序消息推送通道执行：

- URL 首次配置验证：
  - 微信会先发 `GET`
  - 请求参数 `signature`
  - 请求参数 `timestamp`
  - 请求参数 `nonce`
  - 请求参数 `echostr`
  - 服务端验签通过后原样返回 `echostr`

- 明文模式：
  - 请求参数 `signature`
  - 请求参数 `timestamp`
  - 请求参数 `nonce`
  - 服务端配置 `message_push_token`
  - 计算规则：`sha1(sort(token,timestamp,nonce))`
- 安全模式：
  - 请求参数 `msg_signature`
  - 请求参数 `timestamp`
  - 请求参数 `nonce`
  - 报文 `Encrypt`
  - 服务端配置 `message_push_token`
  - 服务端配置 `message_push_encoding_aes_key`

## 8. ACK Contract

通知处理只有在本地业务处理成功后才返回成功 ACK。

允许的回包格式：

- XML
- JSON

当前实现统一返回：

- 成功：`ErrCode=0, ErrMsg=success`
- 失败：`ErrCode=1, ErrMsg=<具体错误>`

成功回包样例：

```json
{
  "ErrCode": 0,
  "ErrMsg": "success"
}
```

失败回包样例：

```json
{
  "ErrCode": 1,
  "ErrMsg": "虚拟支付消息推送签名校验失败"
}
```

## 9. Freeze Rule

若以下任一项缺失，不应声称已完成真实联调：

- 微信后台 `GET/echostr` URL 验证未通过
- 沙箱消息推送模式未确认
- `message_push_token` 缺失
- 安全模式下 `message_push_encoding_aes_key` 缺失
- 测试用户 `session_key` 为空
- 商品未配置 `vp_product_id`
- 沙箱推送无法到达 `notify/virtualPayment`
