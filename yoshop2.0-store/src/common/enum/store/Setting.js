import Enum from '../enum'

/**
 * 枚举类：商城设置
 * SettingEnum
 */
export default new Enum([
  { key: 'DELIVERY', name: '配送设置', value: 'delivery' },
  { key: 'TRADE', name: '交易设置', value: 'trade' },
  { key: 'STORAGE', name: '上传设置', value: 'storage' },
  { key: 'PRINTER', name: '小票打印', value: 'printer' },
  { key: 'FULL_FREE', name: '满额包邮设置', value: 'full_free' },
  { key: 'RECHARGE', name: '充值设置', value: 'recharge' },
  { key: 'POINTS', name: '积分设置', value: 'points' },
  { key: 'SUBMSG', name: '订阅消息设置', value: 'submsg' },
  { key: 'APP_THEME', name: '店铺页面风格', value: 'app_theme' },
  { key: 'PAGE_CATEGORY_TEMPLATE', name: '分类页模板', value: 'page_category_template' },
  { key: 'CUSTOMER', name: '商城客服设置', value: 'customer' }
])
