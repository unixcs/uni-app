import request from '@/utils/request'

// api地址
const api = {
  todoCounts: 'order/todoCounts',
  list: 'order/list',
  detail: 'order/detail',
  cancel: 'order/cancel',
  pay: 'order/pay'
}

// 当前用户待处理的订单数量
export function todoCounts(param, option) {
  return request.get(api.todoCounts, param, option)
}

// 我的订单列表
export function list(param, option) {
  return request.get(api.list, param, option)
}

// 订单详情
export function detail(orderId, param) {
  return request.get(api.detail, { orderId, ...param })
}

// 取消订单
export function cancel(orderId, data) {
  return request.post(api.cancel, { orderId, ...data })
}
