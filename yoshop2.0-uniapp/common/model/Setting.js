import store from '@/store'
import Config from '@/core/config'
import storage from '@/utils/storage'
import * as SettingApi from '@/api/setting'
import SettingKeyEnum from '@/common/enum/setting/Key'
import platform from '@/core/platform'

const CACHE_KEY = 'Setting'

// 写入缓存, 到期时间10分钟
const setStorage = data => {
  const expireTime = 10 * 60
  storage.set(CACHE_KEY, data, expireTime)
}

// 获取缓存中的数据
const getStorage = () => {
  return storage.get(CACHE_KEY)
}

// 获取后端接口商城设置 (最新)
const getApiData = () => {
  return new Promise((resolve, reject) => {
    SettingApi.data()
      .then(result => {
        resolve(result.data.setting)
      })
  })
}

/**
 * 获取商城设置
 * 有缓存的情况下返回缓存, 没有缓存从后端api获取
 * @param {bool} isCache 是否从缓存中获取 [优点不用每次请求后端api 缺点后台更新设置后需等待时效性]
 */
const data = (isCache = undefined) => {
  if (isCache == undefined) {
    isCache = Config.get('enabledSettingCache')
  }
  return new Promise((resolve, reject) => {
    const cacheData = getStorage()
    if (isCache && cacheData) {
      resolve(cacheData)
    } else {
      getApiData().then(data => {
        setStorage(data)
        resolve(data)
      })
    }
  })
}

// 获取商城设置(指定项)
const item = (key, isCache = undefined) => {
  return new Promise((resolve, reject) => {
    data(isCache).then(setting => resolve(setting[key]))
  })
}

// 设置全局自定义主题
const setAppTheme = () => {
  return new Promise((resolve, reject) => {
    item(SettingKeyEnum.APP_THEME.value).then(appTheme => {
      store.dispatch('SetAppTheme', appTheme)
      resolve()
    })
  })
}

// 是否显示客服按钮 (微信小程序客服只有在微信小程序端显示)
const isShowCustomerBtn = async () => {
  const setting = await item(SettingKeyEnum.CUSTOMER.value, true)
  if (!setting.enabled) {
    return false
  }
  return setting.provider === 'mpwxkf' && platform === 'MP-WEIXIN'
}

export default {
  setStorage,
  data,
  item,
  setAppTheme,
  isShowCustomerBtn
}