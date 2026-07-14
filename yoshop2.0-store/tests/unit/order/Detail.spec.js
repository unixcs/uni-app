import moment from 'moment'
import * as Api from '@/api/order'
import OrderDetail from '@/views/order/Detail.vue'
import { shallowMount } from '@vue/test-utils'

jest.mock('@/api/order', () => ({ detail: jest.fn() }))
jest.mock('@/api/order/event', () => ({}))
jest.mock('@/components/Table', () => ({ GoodsItem: { name: 'GoodsItem', render: h => h('div') } }))
jest.mock('@/views/order/modules', () => ({
  PriceForm: { name: 'PriceForm', render: h => h('div') },
  RemarkForm: { name: 'RemarkForm', render: h => h('div') }
}))

const callComputed = (name, context) => OrderDetail.computed[name].call(context)

const detailStubs = [
  'a-spin',
  'a-alert',
  'a-card',
  'a-steps',
  'a-step',
  'a-tag',
  'a-button',
  'a-descriptions',
  'a-descriptions-item',
  'a-tooltip',
  'a-table'
]

describe('merchant order detail rendering guards', () => {
  beforeEach(() => {
    jest.clearAllMocks()
  })
  test('formats a virtual-payment notify timestamp without a Vue $moment plugin', () => {
    const timestamp = 1783793694
    const context = Object.freeze({
      virtualPaymentSummary: { last_notify_time: timestamp }
    })

    expect(callComputed('virtualPaymentLastNotifyText', context))
      .toBe(moment.unix(timestamp).format('YYYY-MM-DD HH:mm:ss'))
  })

  test.each([0, -1, 'invalid', '', null, undefined])('renders an empty notify timestamp %p as placeholder', value => {
    expect(callComputed('virtualPaymentLastNotifyText', {
      virtualPaymentSummary: { last_notify_time: value }
    })).toBe('--')
  })

  test('uses placeholders for unknown enum values instead of throwing during render', () => {
    expect(OrderDetail.methods.getPayStatusText('legacy-status')).toBe('--')
    expect(OrderDetail.methods.getPaymentMethodText('legacy-method')).toBe('--')
    expect(OrderDetail.methods.getPayStatusText(20)).toBe('已支付')
    expect(OrderDetail.methods.getPaymentMethodText('wechat')).toBe('微信支付')
  })

  test('turns an invalid API detail payload into a visible load error state', async () => {
    Api.detail.mockResolvedValueOnce({ data: { detail: null } })
    const context = {
      orderId: 10514,
      isLoading: false,
      loadError: '',
      record: { order_id: 1 },
      initProgress: jest.fn()
    }

    await OrderDetail.methods.getDetail.call(context)

    expect(context.isLoading).toBe(false)
    expect(context.record).toEqual({})
    expect(context.loadError).toContain('接口未返回有效订单详情')
    expect(context.initProgress).not.toHaveBeenCalled()
  })

  test('accepts a valid API detail payload and clears the error state', async () => {
    const detail = { order_id: 10514, order_no: '334517902292559509' }
    Api.detail.mockResolvedValueOnce({ data: { detail } })
    const context = {
      orderId: 10514,
      isLoading: false,
      loadError: 'old error',
      record: {},
      initProgress: jest.fn()
    }

    await OrderDetail.methods.getDetail.call(context)

    expect(context.isLoading).toBe(false)
    expect(context.loadError).toBe('')
    expect(context.record).toBe(detail)
    expect(context.initProgress).toHaveBeenCalledTimes(1)
  })
  test('renders an explicit alert instead of a blank detail when the API payload is invalid', async () => {
    Api.detail.mockResolvedValueOnce({ data: { detail: null } })
    const wrapper = shallowMount(OrderDetail, {
      mocks: {
        $route: { query: { orderId: 10514 } },
        $auth: () => false,
        $module: () => false
      },
      stubs: detailStubs
    })
    await new Promise(resolve => setImmediate(resolve))
    await wrapper.vm.$nextTick()

    expect(wrapper.vm.loadError).toContain('接口未返回有效订单详情')
    expect(wrapper.find('a-alert-stub').exists()).toBe(true)
    expect(wrapper.find('.order-content').exists()).toBe(false)
    wrapper.destroy()
  })

  test('keeps the App Store risk alert, inquiry timeline and backend action gates in the detail template', () => {
    const source = require('fs').readFileSync(require('path').resolve(__dirname, '../../../src/views/order/Detail.vue'), 'utf8')

    expect(source).toContain('v-if="iosRefundRiskStatus >= 10"')
    expect(source).toContain(':description="iosRefundRiskDescription"')
    expect(source).toContain('v-for="item in iosRefundTimeline"')
    expect(source).toContain('v-if="canStartService && canUseStartServiceAction"')
    expect(source).toContain('v-if="canCompleteService && canUseCompleteServiceAction"')
  })

  test('renders the paid real-order shape after a non-zero virtual notify timestamp', async () => {
    const timestamp = 1783793694
    const detail = {
      order_id: 10514,
      order_no: '334517902292559509',
      pay_status: 20,
      pay_method: 'wechat',
      order_status: 10,
      delivery_status: 10,
      receipt_status: 10,
      state_text: '待开始服务',
      total_price: '0.02',
      pay_price: '0.02',
      update_price: { value: '0.00', symbol: '+' },
      user: { user_id: 10004, nick_name: '回归用户' },
      goods: [{
        order_goods_id: 10514,
        goods_name: '002',
        goods_price: '0.02',
        total_num: 1,
        total_price: '0.02'
      }],
      trade: { out_trade_no: '334517912054757667', trade_no: '' },
      backend_action_flags: {},
      virtual_payment_summary: {
        enabled: true,
        env: 0,
        product_id: 'vip002',
        goods_price: 2,
        trade_state: 20,
        notify_times: 1,
        last_notify_time: timestamp
      }
    }
    Api.detail.mockResolvedValueOnce({ data: { detail } })

    const wrapper = shallowMount(OrderDetail, {
      mocks: {
        $route: { query: { orderId: 10514 } },
        $auth: () => false,
        $module: () => false
      },
      stubs: detailStubs
    })
    await new Promise(resolve => setImmediate(resolve))
    await wrapper.vm.$nextTick()

    expect(wrapper.vm.loadError).toBe('')
    expect(wrapper.find('.order-content').exists()).toBe(true)
    expect(wrapper.text()).toContain('334517902292559509')
    expect(wrapper.text()).toContain(moment.unix(timestamp).format('YYYY-MM-DD HH:mm:ss'))
    wrapper.destroy()
  })
})
