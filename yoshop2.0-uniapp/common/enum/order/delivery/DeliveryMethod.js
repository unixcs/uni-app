import Enum from '../../enum'

/**
 * 枚举类：发货方式
 * DeliveryMethodEnum
 */
export default new Enum([
  { key: 'MANUAL', name: '手动发货', value: 10 },
  { key: 'UNWANTED', name: '无需物流', value: 20 },
  { key: 'EORDER', name: '电子面单', value: 30 }
])
