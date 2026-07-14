const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const { parse } = require('@vue/compiler-sfc')

const file = path.resolve(__dirname, '../../pages/refund/detail.vue')
const source = fs.readFileSync(file, 'utf8')
const { descriptor, errors } = parse(source, { filename: file })
assert.deepStrictEqual(errors, [], 'refund detail SFC must parse')
assert.ok(descriptor.script && descriptor.template, 'refund detail must contain script and template')

let script = descriptor.script.content
  .replace(/^\s*import\s+.*$/gm, '')
  .replace(/export\s+default/, 'module.exports =')

let detailHandler = () => Promise.reject(new Error('contract failure'))
const context = {
  module: { exports: {} },
  exports: {},
  RefundApi: { detail: (...args) => detailHandler(...args) },
  IosAppleRefundGuide: {},
  console,
  Promise,
  Error
}
vm.createContext(context)
new vm.Script(script, { filename: file }).runInContext(context)
const component = context.module.exports

const createInstance = () => {
  const instance = {
    ...component.data(),
    orderRefundId: 123,
    toasts: [],
    $toast(message) { this.toasts.push(message) }
  }
  Object.entries(component.methods).forEach(([name, method]) => {
    instance[name] = method.bind(instance)
  })
  return instance
}

;(async () => {
  const failed = createInstance()
  await failed.getPageData()
  assert.strictEqual(failed.isLoading, false, 'failure must clear loading')
  assert.strictEqual(failed.loadError, '退款详情加载失败，请稍后重试', 'failure must persist a safe error')
  assert.deepStrictEqual(JSON.parse(JSON.stringify(failed.detail)), { orderGoods: {} }, 'failure must clear stale detail')
  assert.deepStrictEqual(failed.toasts, ['退款详情加载失败，请稍后重试'], 'failure must give immediate feedback')

  detailHandler = () => Promise.resolve({ data: { detail: { orderGoods: { goods_id: 9 }, display_state_text: '等待 App Store 退款处理' } } })
  const succeeded = createInstance()
  await succeeded.getPageData()
  assert.strictEqual(succeeded.isLoading, false, 'success must clear loading')
  assert.strictEqual(succeeded.loadError, '', 'success must clear prior error')
  assert.strictEqual(succeeded.detail.orderGoods.goods_id, 9, 'success must retain validated detail')

  const template = descriptor.template.content
  assert.match(template, /!isLoading\s*&&\s*loadError/, 'template must render persistent error state')
  assert.match(template, /重新加载/, 'template must expose retry action')
  assert.match(template, /v-else-if="!isLoading"/, 'normal detail must stay hidden on failure')

  console.log('PASS miniapp iOS refund detail error contract')
})().catch(error => {
  console.error(error)
  process.exit(1)
})
