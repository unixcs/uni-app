import * as Api from '@/api/order/refund'
import RefundDetail from '@/views/order/refund/Detail.vue'

jest.mock('@/api/order/refund', () => ({ detail: jest.fn() }))
jest.mock('@/components/Table', () => ({
  GoodsItem: { name: 'GoodsItem', render: h => h('div') },
  UserItem: { name: 'UserItem', render: h => h('div') }
}))
jest.mock('@/views/order/refund/modules', () => ({
  AuditForm: { name: 'AuditForm', render: h => h('div') }
}))

describe('merchant refund detail loading guards', () => {
  beforeEach(() => {
    jest.clearAllMocks()
  })

  test('keeps App Store source, permanent-lock warning and inquiry timeline in the refund detail template', () => {
    const source = require('fs').readFileSync(require('path').resolve(__dirname, '../../../../src/views/order/refund/Detail.vue'), 'utf8')

    expect(source).toContain('v-if="isIosAppleRefundMode"')
    expect(source).toContain('原订单服务已冻结')
    expect(source).toContain('用户在 App Store 申请退款')
    expect(source).toContain('v-for="item in iosRefundTimeline"')
  })

  test('shows a load error state for an invalid detail payload', async () => {
    Api.detail.mockResolvedValueOnce({ data: { detail: null } })
    const context = {
      orderRefundId: 10237,
      isLoading: false,
      loadError: '',
      record: { order_refund_id: 1 },
      goodsList: [{}],
      isServiceOrder: true
    }

    await RefundDetail.methods.getDetail.call(context)

    expect(context.isLoading).toBe(false)
    expect(context.loadError).toContain('接口未返回有效售后单详情')
    expect(context.record).toEqual({})
    expect(context.goodsList).toEqual([])
    expect(context.isServiceOrder).toBe(false)
  })

  test('normalizes missing optional relations so rendering stays safe', async () => {
    const detail = { order_refund_id: 10237, status: 10 }
    Api.detail.mockResolvedValueOnce({ data: { detail } })
    const context = {
      orderRefundId: 10237,
      isLoading: false,
      loadError: 'old error',
      record: {},
      goodsList: [],
      isServiceOrder: false
    }

    await RefundDetail.methods.getDetail.call(context)

    expect(context.isLoading).toBe(false)
    expect(context.loadError).toBe('')
    expect(context.record.orderData).toEqual({})
    expect(context.record.user).toEqual({})
    expect(context.record.orderGoods).toEqual({})
    expect(context.goodsList).toEqual([{}])
    expect(context.isServiceOrder).toBe(true)
  })
})
