import { shallowMount } from '@vue/test-utils'
import * as Api from '@/api/order'
import OrderIndex from '@/views/order/Index.vue'

jest.mock('ant-design-vue/es/empty', () => ({ PRESENTED_IMAGE_SIMPLE: 'empty' }))
jest.mock('ant-design-vue/es/empty/style', () => ({}))
jest.mock('ant-design-vue', () => ({ Empty: { PRESENTED_IMAGE_SIMPLE: 'empty' } }))
jest.mock('@/api/order', () => ({ list: jest.fn() }))
jest.mock('@/components/PlatformIcon', () => ({ render: h => h('div') }))
jest.mock('@/components/Table', () => ({
  GoodsItem: { render: h => h('div') },
  UserItem: { render: h => h('div') }
}))

const form = () => ({ resetFields: jest.fn(), validateFields: jest.fn() })
const mountPage = path => shallowMount(OrderIndex, {
  mocks: {
    $route: { path, meta: { title: '订单' } },
    $form: { createForm: form },
    $auth: () => true
  },
  stubs: ['a-card', 'a-spin', 'a-row', 'a-form', 'a-form-item', 'a-input', 'a-select', 'a-select-option', 'a-checkbox-group', 'a-range-picker', 'a-button', 'a-empty', 'a-pagination', 'a-tag', 'router-link', 'platform-icon']
})

describe('merchant order payment channel filter', () => {
  beforeEach(() => {
    jest.clearAllMocks()
    Api.list.mockResolvedValue({ data: { list: { data: [], total: 0, per_page: 10 } } })
  })

  test('shows channel choices only on all orders', async () => {
    const all = mountPage('/order/list/all')
    await all.vm.$nextTick()
    expect(all.text()).toContain('iOS订单')
    expect(all.text()).toContain('非iOS订单')
    all.destroy()

    const paid = mountPage('/order/list/paid')
    await paid.vm.$nextTick()
    expect(paid.text()).not.toContain('支付渠道')
    paid.destroy()
  })

  test('keeps selected channel when paging and sends it to API', async () => {
    const wrapper = mountPage('/order/list/all')
    await new Promise(resolve => setImmediate(resolve))
    wrapper.vm.queryParam = { paymentChannel: 'ios_apple' }
    wrapper.vm.onChangePage(2)
    await new Promise(resolve => setImmediate(resolve))
    expect(Api.list).toHaveBeenLastCalledWith(expect.objectContaining({
      dataType: 'all', paymentChannel: 'ios_apple', page: 2
    }))
    wrapper.destroy()
  })

  test('renders App Store risk inside the existing status cell without adding a table column', () => {
    const source = require('fs').readFileSync(require('path').resolve(__dirname, '../../../src/views/order/Index.vue'), 'utf8')

    expect(source).toContain('item.backend_action_flags.ios_refund_risk_status >= 10')
    expect(source).toContain("item.backend_action_flags.refund_display_state_text || item.backend_action_flags.ios_refund_risk_text || '服务已冻结'")
    expect(source).not.toContain("title: 'App Store退款'")
  })

  test('detail action preserves authorization, new-tab target and minimum-size class', () => {
    expect(OrderIndex.template).toBeUndefined()
    const source = require('fs').readFileSync(require('path').resolve(__dirname, '../../../src/views/order/Index.vue'), 'utf8')
    expect(source).toContain("$auth('/order/detail')")
    expect(source).toContain('target="_blank"')
    expect(source).toContain('class="detail-button"')
    expect(source).toMatch(/min-width:\s*64px/)
    expect(source).toMatch(/height:\s*32px/)
  })
})
