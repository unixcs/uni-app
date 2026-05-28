import * as Api from '@/api/client/wxapp/setting'

/**
 * 微信小程序设置 model类
 * WxappSettingModel
 */

// 获取微信小程序设置 (指定项)
const item = key => {
  return new Promise((resolve, reject) => {
    Api.detail(key).then(result => resolve(result.data.detail))
  })
}

// 是否开启发货信息管理
const isEnableShipping = () => {
  return new Promise((resolve, reject) => {
    item('basic').then(data => resolve(Boolean(data.enableShipping)))
  })
}

export default { item, isEnableShipping }
