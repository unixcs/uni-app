## miniprogram-automator 获取 `wx.requestVirtualPayment` 参数总结

### 1. 当前项目里真正传给 `wx.requestVirtualPayment` 的参数

按当前实现，前端真正调用的是：

```js
wx.requestVirtualPayment({
  signData,
  paySig,
  signature,
  mode
})
```

也就是说，`wx.requestVirtualPayment` 真正收到的只有 4 个字段：

- `mode`
- `signData`
- `paySig`
- `signature`

其中当前项目固定/来源如下：

- `mode`
  - 固定值：`short_series_goods`
- `signData`
  - 是一个 JSON 字符串
  - 由服务端 `yoshop2.0/app/api/service/cashier/Payment.php` 组装
  - 当前结构为：

```json
{
  "offerId": "1450568898",
  "buyQuantity": 1,
  "env": 1,
  "currencyType": "CNY",
  "productId": "vip0",
  "goodsPrice": 1,
  "outTradeNo": "服务端实时生成",
  "attach": "商城订单号"
}
```

说明：

- `offerId` 来源于虚拟支付配置 `offer_id`
- `buyQuantity` 当前固定为 `1`
- `env` 沙箱为 `1`，现网为 `0`
- `currencyType` 当前固定为 `CNY`
- `productId` 来源于商品 `vp_product_id`
  - 当前联调用例商品 `goods_id=10001` 的值是 `vip0`
- `goodsPrice` 来源于商品 `vp_price_snapshot`
  - 当前联调用例商品 `goods_id=10001` 的值是 `1`，即 `0.01` 元
- `outTradeNo` 为服务端实时生成的虚拟支付交易号
- `attach` 当前放的是商城订单号 `order_no`

- `paySig`
  - 计算方式：

```text
hash_hmac('sha256', 'requestVirtualPayment&' + signData, appKey)
```

  - `appKey` 取值规则：
    - `env=1` 时取沙箱 `sandbox_app_key`
    - `env=0` 时取现网 `production_app_key`

- `signature`
  - 计算方式：

```text
hash_hmac('sha256', signData, session_key)
```

  - `session_key` 来源于当前测试用户的小程序登录态

### 2. 服务端返回里还有哪些辅助字段

服务端返回给前端的不只有 `wx.requestVirtualPayment` 的 4 个入参，还会顺带返回：

- `provider=virtual`
- `platform=wechat_virtual`
- `out_trade_no`
- `outTradeNo`
- `env`
- `product_id`
- `goods_price`
- `attach`
- `payload_snapshot`

这些字段里：

- `outTradeNo/out_trade_no` 方便前端后续主动查单
- `payload_snapshot` 本质上就是 `signData` 对应的原始对象快照
- 但它们不是 `wx.requestVirtualPayment` 的直接入参

### 3. 用 `miniprogram-automator` 抓参数的推荐方法

结论先说：

- `miniprogram-automator` 不能从微信原生支付弹窗里反向读参数
- 最稳的方法是：在 `AppService` 层直接拦截 `wx.requestVirtualPayment`
- 推荐组合：`mockWxMethod + exposeFunction`

可用示例：

```js
const automator = require('miniprogram-automator')

async function captureVirtualPaymentArgs(miniProgram) {
  let captured = null

  await miniProgram.exposeFunction('captureVirtualPaymentArgs', payload => {
    captured = payload
  })

  await miniProgram.mockWxMethod('requestVirtualPayment', function (options) {
    const payload = {
      mode: options.mode,
      signData: options.signData,
      paySig: options.paySig,
      signature: options.signature
    }

    captureVirtualPaymentArgs(payload)

    if (typeof options.fail === 'function') {
      options.fail({ errMsg: 'requestVirtualPayment:fail automator capture only' })
    }

    return { errMsg: 'requestVirtualPayment:fail automator capture only' }
  })

  return () => captured
}
```

建议的获取过程：

1. 用 `automator.launch()` 启动小程序
2. 用 `miniProgram.exposeFunction()` 暴露一个 Node 侧接收函数
3. 用 `miniProgram.mockWxMethod('requestVirtualPayment', fn)` 拦截小程序里的真实调用
4. 在 mock 函数里拿到 `options.mode/signData/paySig/signature`
5. 把 `signData` 再 `JSON.parse()`，即可拿到 `offerId/productId/goodsPrice/outTradeNo/attach`
6. 采集完成后按需要：
   - 仅取证：走 `fail`，避免伪造支付成功
   - 需要继续流程：改成主动调用 `options.success(...)`

### 4. 为什么推荐拦截 `requestVirtualPayment`，而不是抓页面数据

因为页面或接口返回里看到的是“支付前参数包”，而真正传入微信 JSAPI 的最终值，以 `wx.requestVirtualPayment(options)` 被调用那一刻最准确。

直接拦截这个方法有 3 个好处：

- 能拿到最终实参，不会漏掉前端二次加工
- 能确认前端到底传了哪些字段给微信
- 拿到的 `signData`、`paySig`、`signature` 可以直接落盘做联调证据

### 5. 抓到参数后怎么解析

拿到 `captured.signData` 后直接：

```js
const signDataObject = JSON.parse(captured.signData)
```

就能得到本次支付对应的：

- `offerId`
- `buyQuantity`
- `env`
- `currencyType`
- `productId`
- `goodsPrice`
- `outTradeNo`
- `attach`

这就是 `miniprogram-automator` 获取虚拟支付对应参数值的最直接方法。
