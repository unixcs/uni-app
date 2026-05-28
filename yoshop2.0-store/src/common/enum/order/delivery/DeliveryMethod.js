import Enum from '../../enum'

/**
 * 枚举类：订单导出状态
 * DeliveryMethodEnum
 */
export default new Enum([
  { key: 'MANUAL', name: '手动录入', value: 10 },
  { key: 'NORMAL', name: '无需物流', value: 20 }
])
