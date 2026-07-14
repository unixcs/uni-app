const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const { parse } = require('@vue/compiler-sfc')

const file = path.resolve(__dirname, '../../pages/checkout/cashier/index.vue')
const source = fs.readFileSync(file, 'utf8')
const { descriptor, errors } = parse(source, { filename: file })
assert.deepStrictEqual(errors, [], 'cashier SFC must parse')
assert.ok(descriptor.script, 'cashier must contain a script block')

let script = descriptor.script.content
  .replace(/^\s*import\s+.*$/gm, '')
  .replace(/export\s+default/, 'module.exports =')

const store = new Map()
const storage = {
  get(key) { return store.get(key) },
  set(key, value) { store.set(key, value) },
  remove(key) { store.delete(key) }
}
const PayMethodEnum = {
  WECHAT: { value: 'wechat', name: '微信支付' },
  ALIPAY: { value: 'alipay', name: '支付宝' },
  BALANCE: { value: 'balance', name: '余额支付' }
}
const PayStatusEnum = {
  PENDING: { value: 10 },
  SUCCESS: { value: 20 }
}
let orderPayHandler = () => Promise.reject(new Error('orderPay handler missing'))
let orderInfoHandler = () => Promise.resolve({
  data: {
    order: { pay_status: 10, pay_price: '1.00' },
    personal: { balance: '0.00' },
    paymentMethods: [{ method: 'wechat', is_default: true }]
  }
})
let tradeQueryHandler = () => Promise.reject(new Error('tradeQuery handler missing'))
let wechatPaymentHandler = () => Promise.reject(new Error('wechat payment handler missing'))
let alipayPaymentHandler = () => Promise.reject(new Error('alipay payment handler missing'))
const CashierApi = {
  orderPay: (...args) => orderPayHandler(...args),
  orderInfo: (...args) => orderInfoHandler(...args),
  tradeQuery: (...args) => tradeQueryHandler(...args)
}
const Wechat = {
  extraAsUnify: () => Promise.resolve({}),
  payment: (...args) => wechatPaymentHandler(...args)
}
const Alipay = {
  extraAsUnify: () => ({}),
  payment: (...args) => alipayPaymentHandler(...args)
}
const uniEvents = []
const navCalls = []
const context = {
  module: { exports: {} },
  exports: {},
  storage,
  inArray: (value, items) => items.includes(value),
  urlEncode: value => value,
  Alipay,
  Wechat,
  CountDown: {},
  PayMethodEnum,
  PayStatusEnum,
  CashierApi,
  isH5: false,
  isMpWeixin: true,
  isWeixinOfficial: false,
  console,
  Promise,
  Error,
  Date,
  setTimeout: callback => { callback(); return 1 },
  clearTimeout: () => {},
  getCurrentPages: () => [{ route: 'pages/checkout/cashier/index' }],
  uni: {
    $emit: (...args) => uniEvents.push(args),
    navigateBack: (...args) => navCalls.push(['back', ...args])
  }
}
vm.createContext(context)
new vm.Script(script, { filename: file }).runInContext(context)
const component = context.module.exports

const flush = () => new Promise(resolve => setImmediate(resolve))
const createDeferred = () => {
  let resolve
  let reject
  const promise = new Promise((res, rej) => { resolve = res; reject = rej })
  return { promise, resolve, reject }
}
const createInstance = () => {
  const instance = {
    ...component.data(),
    orderId: 1001,
    order: { pay_status: 10, pay_price: '1.00' },
    platform: 'MP-WEIXIN',
    curPaymentItem: { method: 'wechat' },
    methods: [{ method: 'wechat', is_default: true }],
    toasts: [],
    errors: [],
    navs: [],
    $toast(message) { this.toasts.push(message) },
    $error(message) { this.errors.push(message) },
    $navTo(...args) { this.navs.push(args) }
  }
  Object.entries(component.methods).forEach(([name, method]) => {
    instance[name] = method.bind(instance)
  })
  return instance
}

;(async () => {
  assert.ok('paymentPhase' in component.data(), 'cashier must expose an explicit payment phase')

  // One user intent stays locked until the native payment/convergence promise settles.
  const nativeDeferred = createDeferred()
  let orderPayCalls = 0
  let paymentCalls = 0
  orderPayHandler = () => {
    orderPayCalls += 1
    return Promise.resolve({
      data: { payment: { provider: 'virtual', state: 'created', outTradeNo: 'trade-a' } }
    })
  }
  wechatPaymentHandler = () => {
    paymentCalls += 1
    return nativeDeferred.promise
  }
  const locked = createInstance()
  const firstSubmit = locked.handleSubmit()
  await flush()
  locked.handleSubmit()
  await flush()
  assert.strictEqual(orderPayCalls, 1, 'double submit must call orderPay once')
  assert.strictEqual(paymentCalls, 1, 'double submit must open one native cashier')
  assert.strictEqual(locked.disabled, true, 'button must remain locked while native cashier is unresolved')
  nativeDeferred.resolve({
    res: {},
    option: { isRequireQuery: true, outTradeNo: 'trade-a', method: 'wechat' }
  })
  tradeQueryHandler = () => Promise.resolve({ data: { isPay: true, isPending: false }, message: 'paid' })
  await firstSubmit
  assert.strictEqual(locked.paymentPhase, 'success', 'paid convergence must reach success')

  // Pending is not failure: retry the same outTradeNo and converge without another cashier.
  let queryCalls = 0
  tradeQueryHandler = ({ outTradeNo }) => {
    queryCalls += 1
    assert.strictEqual(outTradeNo, 'trade-b', 'polling must keep the same transaction number')
    if (queryCalls === 1) {
      return Promise.resolve({ data: { isPay: false, isPending: true }, message: 'confirming' })
    }
    return Promise.resolve({ data: { isPay: true, isPending: false }, message: 'paid' })
  }
  const pending = createInstance()
  await pending.onTradeQuery('trade-b', 'wechat')
  assert.strictEqual(queryCalls, 2, 'pending result must be retried')
  assert.strictEqual(pending.paymentPhase, 'success', 'pending then paid must converge')

  // Concurrent recovery/success signals for one trade join one convergence promise.
  const queryDeferred = createDeferred()
  queryCalls = 0
  tradeQueryHandler = () => {
    queryCalls += 1
    return queryDeferred.promise
  }
  const raced = createInstance()
  const recovery = raced.onTradeQuery('trade-c', 'wechat')
  const nativeSuccess = raced.onTradeQuery('trade-c', 'wechat')
  assert.strictEqual(recovery, nativeSuccess, 'same-trade convergence calls must share one promise')
  assert.strictEqual(queryCalls, 1, 'same-trade race must send one query at a time')
  queryDeferred.resolve({ data: { isPay: true, isPending: false }, message: 'paid once' })
  await recovery
  raced.onShowSuccess({ message: 'duplicate paid' })
  assert.strictEqual(raced.toasts.filter(item => /paid|支付|付款/.test(item)).length, 1, 'success feedback must be idempotent')
  assert.strictEqual(uniEvents.length >= 1, true, 'success must emit refresh')

  // Backend confirming response must query the existing transaction and never open a cashier.
  paymentCalls = 0
  tradeQueryHandler = () => Promise.resolve({ data: { isPay: true, isPending: false }, message: 'already paid' })
  const guarded = createInstance()
  await guarded.onSubmitCallback({
    data: { payment: { provider: 'virtual', state: 'confirming', outTradeNo: 'trade-old' } },
    message: 'confirm old trade'
  })
  assert.strictEqual(paymentCalls, 0, 'confirming response must not open native cashier')
  assert.strictEqual(guarded.paymentPhase, 'success', 'existing paid trade must converge')

  // Polling exhaustion remains fail-closed: keep the same trade recovery marker and button lock.
  queryCalls = 0
  tradeQueryHandler = ({ outTradeNo }) => {
    queryCalls += 1
    assert.strictEqual(outTradeNo, 'trade-pending', 'exhausted polling must not switch transactions')
    return Promise.resolve({ data: { isPay: false, isPending: true }, message: 'still confirming' })
  }
  const exhausted = createInstance()
  exhausted.orderId = 2002
  await exhausted.onTradeQuery('trade-pending', 'wechat')
  assert.strictEqual(queryCalls, 6, 'polling budget must be immediate query plus five retries')
  assert.strictEqual(exhausted.paymentPhase, 'awaiting_confirmation', 'exhausted polling must remain awaiting confirmation')
  assert.strictEqual(exhausted.disabled, true, 'unknown payment result must keep submit locked')
  const retainedTrade = store.get('tempUnifyData_2002')
  assert.strictEqual(retainedTrade.outTradeNo, 'trade-pending', 'unknown payment result must retain the same trade number')
  assert.strictEqual(retainedTrade.method, 'wechat', 'unknown payment result must retain the payment method')

  // Explicit native cancel clears active recovery but carries a one-shot retry marker to backend verification.
  const cancelled = createInstance()
  cancelled.orderId = 3003
  await cancelled.onPayFail({ isUserCancel: true, message: 'cancel' }, {
    phase: 'payment', method: 'wechat', provider: 'virtual', outTradeNo: 'trade-cancelled'
  })
  assert.strictEqual(cancelled.paymentPhase, 'idle', 'explicit cancel must return to idle')
  assert.strictEqual(store.has('tempUnifyData_3003'), false, 'explicit cancel must clear active recovery data')
  const retryExtra = await cancelled.getExtraAsUnify('wechat')
  assert.strictEqual(retryExtra.previousCancelledOutTradeNo, 'trade-cancelled', 'retry must identify the explicitly cancelled trade')

  let retryPayload = null
  paymentCalls = 0
  orderPayHandler = (orderId, payload) => {
    retryPayload = payload
    return Promise.resolve({
      data: { payment: { provider: 'virtual', state: 'created', outTradeNo: 'trade-retry' } }
    })
  }
  wechatPaymentHandler = () => {
    paymentCalls += 1
    return Promise.resolve({
      res: {},
      option: { isRequireQuery: true, outTradeNo: 'trade-retry', method: 'wechat' }
    })
  }
  tradeQueryHandler = () => Promise.resolve({ data: { isPay: true, isPending: false }, message: 'retry paid' })
  await cancelled.handleSubmit()
  assert.strictEqual(retryPayload.extra.previousCancelledOutTradeNo, 'trade-cancelled', 'retry marker must reach orderPay')
  assert.strictEqual(paymentCalls, 1, 'verified retry may open exactly one new cashier')
  assert.strictEqual(store.has('cancelledVirtualTrade_3003'), false, 'created retry must consume the cancellation marker')

  // A definitive pre-cashier capability rejection is retryable only through backend verification.
  const unavailable = createInstance()
  unavailable.orderId = 4004
  unavailable.rememberActiveTrade('trade-unavailable', 'wechat')
  await unavailable.onPayFail({
    shouldSkipAutoQuery: true,
    message: 'capability unavailable'
  }, {
    phase: 'payment', method: 'wechat', provider: 'virtual', outTradeNo: 'trade-unavailable'
  })
  assert.strictEqual(unavailable.paymentPhase, 'idle', 'definitive pre-cashier rejection may return to idle')
  assert.strictEqual(store.has('tempUnifyData_4004'), false, 'pre-cashier rejection must clear active recovery data')
  assert.strictEqual(store.get('cancelledVirtualTrade_4004').outTradeNo, 'trade-unavailable', 'pre-cashier rejection must carry a backend verification marker')

  // A contended backend lock can fail closed without a transaction number; frontend must not reopen payment.
  paymentCalls = 0
  const contended = createInstance()
  await contended.onSubmitCallback({
    data: { payment: { provider: 'virtual', state: 'confirming', message: 'request in progress' } },
    message: 'request in progress'
  })
  assert.strictEqual(paymentCalls, 0, 'confirming without a trade number must not open native cashier')
  assert.strictEqual(contended.paymentPhase, 'awaiting_confirmation', 'lock contention must remain fail-closed')
  assert.strictEqual(contended.disabled, true, 'lock contention must keep submit locked')

  // Shared cashier compatibility: balance still finalizes directly and Alipay still converges by query.
  const balance = createInstance()
  balance.curPaymentItem = { method: 'balance' }
  await balance.onSubmitCallback({ data: { payment: {} }, message: 'balance paid' })
  assert.strictEqual(balance.paymentPhase, 'success', 'balance payment must keep direct-success behavior')

  let alipayCalls = 0
  alipayPaymentHandler = () => {
    alipayCalls += 1
    return Promise.resolve({
      res: {},
      option: { isRequireQuery: true, outTradeNo: 'trade-alipay', method: 'alipay' }
    })
  }
  tradeQueryHandler = ({ outTradeNo, method }) => {
    assert.strictEqual(outTradeNo, 'trade-alipay', 'Alipay must query its original transaction')
    assert.strictEqual(method, 'alipay', 'Alipay query must retain its payment method')
    return Promise.resolve({ data: { isPay: true, isPending: false }, message: 'alipay paid' })
  }
  const alipay = createInstance()
  alipay.curPaymentItem = { method: 'alipay' }
  await alipay.onSubmitCallback({
    data: { payment: { provider: 'alipay', out_trade_no: 'trade-alipay' } }
  })
  assert.strictEqual(alipayCalls, 1, 'Alipay must still open its payment client once')
  assert.strictEqual(alipay.paymentPhase, 'success', 'Alipay success must converge through trade query')

  console.log('PASS miniapp payment convergence contract')
})().catch(error => {
  console.error(error)
  process.exit(1)
})
