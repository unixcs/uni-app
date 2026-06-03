import Enum from '../enum'

/**
 * 枚举类：订单类型
 * DataTypeEnum
 */
export default new Enum([
  { key: 'ALL', name: '全部', value: 'all' },
  { key: 'PAY', name: '待付款', value: 'pay' },
  { key: 'CONTACT', name: '待联系', value: 'contact' },
  { key: 'IN_SERVICE', name: '服务中', value: 'in_service' },
  { key: 'COMPLETE', name: '已完成', value: 'complete' },
  { key: 'CANCEL', name: '已关闭', value: 'cancel' },
  { key: 'REFUND', name: '已退款', value: 'refund' }
])
