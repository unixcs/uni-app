import Enum from '../enum'

/**
 * 枚举类：设置项索引
 * SettingKeyEnum
 */
export default new Enum([{
    key: 'REGISTER',
    name: '账户注册设置',
    value: 'register'
  },
  {
    key: 'APP_THEME',
    name: '店铺页面风格',
    value: 'app_theme'
  },
  {
    key: 'PAGE_CATEGORY_TEMPLATE',
    name: '分类页模板',
    value: 'page_category_template'
  },
  {
    key: 'POINTS',
    name: '积分设置',
    value: 'points'
  },
  {
    key: 'RECHARGE',
    name: '充值设置',
    value: 'recharge'
  },
  {
    key: 'CUSTOMER',
    name: '商城客服设置',
    value: 'customer'
  }
])
