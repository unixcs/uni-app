import Enum from '../enum'

/**
 * 枚举类：支付方式类型
 * PayMethodEnum
 */
export default new Enum([
  { key: 'WECHAT', name: '微信支付', value: 'wechat' },
  { key: 'ALIPAY', name: '支付宝支付', value: 'alipay' },
  { key: 'BALANCE', name: '余额支付', value: 'balance' }
])
