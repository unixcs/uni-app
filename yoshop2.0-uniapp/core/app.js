import store from '@/store'
import * as util from '@/utils/util'
import { paginate } from '@/common/constant'
import Config from './config'

/**
 * 显示成功提示框
 */
export const showSuccess = (msg, callback) => {
  uni.showToast({
    title: msg,
    icon: 'success',
    // #ifndef MP-ALIPAY
    mask: true,
    // #endif
    duration: 1500,
    success() {
      callback && callback()
    }
  })
}

/**
 * 显示失败提示框
 */
export const showError = (msg, callback) => {
  uni.showModal({
    title: '友情提示',
    content: msg,
    showCancel: false,
    success(res) {
      callback && callback()
    }
  })
}

/**
 * 显示纯文字提示框
 */
export const showToast = (msg, duration = 1500, mask = true) => {
  uni.showToast({
    title: msg, // 提示的内容
    icon: 'none',
    // #ifndef MP-ALIPAY
    mask, // 是否显示透明蒙层，防止触摸穿透 (支付宝小程序不支持)
    // #endif
    duration // 提示的延迟时间，单位毫秒，默认：1500	
  })
}

/**
 * tabBar页面路径列表 (用于链接跳转时判断)
 * tabBarLinks为常量, 无需修改
 */
export const getTabBarLinks = () => {
  const tabBarLinks = [
    'pages/index/index',
    'pages/category/index',
    'pages/cart/index',
    'pages/user/index'
  ]
  return tabBarLinks
}

/**
 * 生成完整的H5地址 [带参数]
 * @param {string} baseUrl H5访问地址
 * @param {string} path 页面路径
 * @param {object} params 页面参数
 * @return {string}
 */
export const buildUrL = (h5Url, path, params) => {
  let complete = h5Url
  if (!util.isEmpty(path)) {
    complete += '#/' + path
    const shareParamsStr = getShareUrlParams(params)
    if (!util.isEmpty(shareParamsStr)) {
      complete += '?' + shareParamsStr
    }
  }
  return complete
}

/**
 * 生成转发的url参数(string格式)
 * @param {object} params
 * @return {string}
 */
export const getShareUrlParams = params => {
  return util.urlEncode(getShareParams(params))
}

/**
 * 生成转发的url参数(object格式)
 * @param {object} params
 * @return {object}
 */
export const getShareParams = params => {
  return {
    ...params
  }
}

/**
 * 跳转到指定页面url
 * 支持tabBar页面
 * @param {string}  url   页面路径
 * @param {object}  query 页面参数
 * @param {string}  modo  跳转类型(默认navigateTo)
 */
export const navTo = (url, query = {}, modo = 'navigateTo') => {
  if (!url || url.length == 0) {
    return false
  }
  // tabBar页面, 使用switchTab
  if (util.inArray(url, getTabBarLinks())) {
    store.dispatch('SetQueryParam', query)
    uni.switchTab({ url: `/${url}` })
    return true
  }
  // 生成query参数
  const queryStr = !util.isEmpty(query) ? '?' + util.urlEncode(query) : ''
  // 普通页面, 使用navigateTo
  modo === 'navigateTo' && uni.navigateTo({
    url: `/${url}${queryStr}`
  })
  // 特殊指定, 使用redirectTo
  modo === 'redirectTo' && uni.redirectTo({
    url: `/${url}${queryStr}`
  })
  return true
}

/**
 * 获取当前页面数据
 * @param {object}
 */
export const getCurrentPage = () => {
  const pages = getCurrentPages()
  const pathInfo = pages[pages.length - 1].$page.fullPath.split('?')
  return { path: pathInfo[0].slice(1), query: util.urlDecode(pathInfo[1]) }
}

/**
 * 获取购物车商品总数量
 * @param {*} value 
 */
export const getCartTotalNum = (value) => {
  const cartTotal = uni.getStorageSync('cartTotalNum') || 0
  return checkLogin() ? cartTotal : 0
}

/**
 * 记录购物车商品总数量
 * @param {*} value 
 */
export const setCartTotalNum = (value) => {
  uni.setStorageSync('cartTotalNum', Number(value))
}

/**
 * 设置购物车tabbar的角标
 * 该方法只能在tabbar页面中调用, 其他页面调用会报错
 */
export const setCartTabBadge = () => {
  const cartTabbarIndex = 2
  const cartTotal = getCartTotalNum()
  if (cartTotal > 0) {
    uni.setTabBarBadge({
      index: cartTabbarIndex,
      text: `${cartTotal}`
    })
  } else {
    uni.removeTabBarBadge({
      index: cartTabbarIndex
    })
  }
  return
}

/**
 * 验证是否已登录
 */
export const checkLogin = () => {
  return !!store.getters.userId
}

/**
 * 加载更多列表数据
 * @param {Object} resList 新列表数据
 * @param {Object} oldList 旧列表数据
 * @param {int} pageNo 当前页码
 */
export const getEmptyPaginateObj = () => {
  return util.cloneObj(paginate)
}

/**
 * 加载更多列表数据
 * @param {Object} resList 新列表数据
 * @param {Object} oldList 旧列表数据
 * @param {int} pageNo 当前页码
 */
export const getMoreListData = (resList, oldList, pageNo) => {
  // 如果是第一页需手动制空列表
  if (pageNo == 1) oldList.data = []
  // 合并新数据
  return oldList.data.concat(resList.data)
}

/**
 * scene解码
 * 用于解码微信小程序二维码参数,并返回对象
 */
export const sceneDecode = (str) => {
  if (str === undefined)
    return {}
  const data = {}
  const params = decodeURIComponent(str).split(',')
  for (const i in params) {
    const val = params[i].split(':');
    val.length > 0 && val[0] && (data[val[0]] = val[1] || null)
  }
  return data
}

/**
 * 获取二维码场景值(scene)
 */
export const getSceneData = query => {
  return util.hasOwnProperty(query, 'scene') ? sceneDecode(query.scene) : {}
}

/**
 * 验证指定的功能模块是否开启
 * fix: 免费版暂无该功能
 */
export const checkModuleKey = moduleKey => {
  return true
}

/**
 * 验证指定的功能模块是否开启（批量）
 */
export const checkModules = moduleKeys => {
  return moduleKeys.filter(val => checkModuleKey(val)).length > 0
}

/**
 * 过滤开启的功能模块（批量）
 */
export const filterModules = array => {
  return array.filter(item => !item.moduleKey || checkModuleKey(item.moduleKey))
}

/**
 * link对象点击事件
 * 支持tabBar页面
 */
export const onLink = linkObj => {
  if (!linkObj) return false
  // 跳转到指定页面
  if (linkObj.type === 'PAGE') {
    navTo(linkObj.param.path, linkObj.param.query)
  }
  // 跳转到自定义路径
  if (linkObj.type === 'CUSTOM') {
    navTo(linkObj.param.path, util.urlDecode(linkObj.param.queryStr))
  }
  // 跳转到微信小程序
  // #ifdef MP-WEIXIN
  if (linkObj.type === 'MP-WEIXIN') {
    uni.navigateToMiniProgram({
      appId: linkObj.param.appId,
      path: linkObj.param.path
    })
  }
  // #endif
  // 跳转到H5外部链接
  if (linkObj.type === 'URL') {
    // #ifdef H5
    window.open(linkObj.param.url)
    // #endif
    // #ifdef APP-PLUS
    plus.runtime.openWeb(linkObj.param.url)
    // #endif
    // #ifdef MP-WEIXIN
    navTo('pages/web-view/index', { src: encodeURIComponent(linkObj.param.url) })
    // #endif
    // #ifdef MP
    // uni.setClipboardData({
    //   data: linkObj.param.url,
    //   success: () => showToast('链接已复制'),
    //   fail: ({ errMsg }) => showToast('复制失败 ' + errMsg)
    // })
    // #endif
  }
  return true
}

// style变量样式转为字符串 (因小程序端不支持styleObject语法)
export const styleObj2Str = appTheme => {
  let str = ''
  for (const index in appTheme) {
    const name = util.formatToLine(index)
    str += `--${name}:${appTheme[index]};`
  }
  return str
}