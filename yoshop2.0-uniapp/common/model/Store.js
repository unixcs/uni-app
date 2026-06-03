import store from '@/store'
import StoreApi from '@/api/store'
import storage from '@/utils/storage'
import SettingModel from '@/common/model/Setting'

const CACHE_KEY = 'Store'

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
    StoreApi.data().then(result => {
      // 初始化商城数据
      initStoreData(result.data)
      resolve(result.data)
    }).catch(reject)
  })
}

// 初始化商城数据
const initStoreData = data => {
  // 将商城基本信息写入缓存
  setStorage(data)
  // 设置商城设置缓存
  SettingModel.setStorage(data.setting)
  // 设置全局自定义主题
  SettingModel.setAppTheme()
}

/**
 * 获取商城基础信息
 * 有缓存的情况下返回缓存, 没有缓存从后端api获取
 */
const data = (isCache = true) => {
  return new Promise((resolve, reject) => {
    const cacheData = getStorage()
    if (isCache && cacheData) {
      resolve(cacheData)
    } else {
      getApiData().then(data => {
        resolve(data)
      }).catch(reject)
    }
  })
}

// 获取商城基本信息
const storeInfo = () => {
  return new Promise((resolve, reject) => {
    data().then(data => resolve(data.storeInfo)).catch(reject)
  })
}

// 获取H5端访问地址
const h5Url = () => {
  return new Promise((resolve, reject) => {
    data().then(data => {
      const h5Url = data.clientData.h5.setting.baseUrl
      resolve(h5Url)
    }).catch(reject)
  })
}

export default {
  data,
  storeInfo,
  h5Url
}
