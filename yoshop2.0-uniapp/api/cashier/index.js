import request from '@/utils/request'

// api地址
const api = {
  orderInfo: 'cashier/orderInfo',
  orderPay: 'cashier/orderPay',
  tradeQuery: 'cashier/tradeQuery',
}

/**
 * 获取支付订单的信息
 * @param {Number} orderId
 * @param {Object} param
 */
export function orderInfo(orderId, param) {
  return request.get(api.orderInfo, { orderId, ...param })
}

/**
 * 确认支付
 * @param {Number} orderId
 * @param {Object} data
 */
export function orderPay(orderId, data) {
  return request.post(api.orderPay, { orderId, ...data })
}

/**
 * 交易查询
 * 查询第三方支付订单是否付款成功
 * @param {Object} param
 */
export function tradeQuery(param) {
  return request.get(api.tradeQuery, param)
}
