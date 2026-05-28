import Enum from '../enum'

/**
 * 枚举类：订单类型
 * DataTypeEnum
 */
export default new Enum([
  { key: 'ALL', name: '全部', value: 'all' },
  { key: 'DELIVERY', name: '待发货', value: 'delivery' },
  { key: 'RECEIPT', name: '待收货', value: 'receipt' },
  { key: 'PAY', name: '待付款', value: 'pay' },
  { key: 'COMPLETE', name: '已完成', value: 'complete' },
  { key: 'APPLY_CANCEL', name: '待取消', value: 'apply_cancel' },
  { key: 'CANCEL', name: '已取消', value: 'cancel' }
])
