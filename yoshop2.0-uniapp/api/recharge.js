import request from '@/utils/request'

// api地址
const api = {
  center: 'recharge/center',
  submit: 'recharge/submit',
  tradeQuery: 'recharge/tradeQuery'
}

// 充值中心页面数据
export const center = (param) => {
  return request.get(api.center, param)
}

// 确认充值订单
export const submit = (data) => {
  return request.post(api.submit, { form: data })
}

/**
 * 交易查询
 * 查询第三方支付订单是否付款成功
 * @param {Object} param
 */
export function tradeQuery(param) {
  return request.get(api.tradeQuery, param)
}
