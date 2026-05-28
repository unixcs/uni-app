import Enum from './enum'

/**
 * 枚举类：客户端类型
 * ClientEnum
 */
export default new Enum([
  { key: 'APP', name: 'APP端', value: 'APP' },
  { key: 'H5', name: 'H5端', value: 'H5' },
  { key: 'MP_WEIXIN', name: '微信小程序端', value: 'MP-WEIXIN' }
])
