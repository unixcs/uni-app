import request from '@/utils/request'

// api地址
const api = {
  list: 'refund/list',
  goods: 'refund/goods',
  apply: 'refund/apply',
  detail: 'refund/detail'
}

// 退款单列表
export const list = (param, option) => {
  return request.get(api.list, param, option)
}

// 订单商品详情
export const goods = (orderGoodsId, param) => {
  return request.get(api.goods, { orderGoodsId, ...param })
}

// 申请退款
export const apply = (orderGoodsId, data) => {
  return request.post(api.apply, { orderGoodsId, form: data })
}

// 退款单详情
export const detail = (orderRefundId, param) => {
  return request.get(api.detail, { orderRefundId, ...param })
}
