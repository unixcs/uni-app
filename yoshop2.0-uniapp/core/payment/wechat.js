import platform from '@/core/platform'
import storage from '@/utils/storage'
import ClientEnum from '@/common/enum/Client'
import { PayMethodEnum } from '@/common/enum/payment'

const VIRTUAL_PAYMENT_AUTOMATOR_ERRCODE = -15101
const VIRTUAL_PAYMENT_SANDBOX_RELEASE_ERRCODE = -15102
const VIRTUAL_PAYMENT_DEVTOOLS_PREVIEW_TIMEOUT_ERRCODE = -15103
const VIRTUAL_PAYMENT_TRIAL_ACCESS_DENIED_ERRCODE = -15104
const VIRTUAL_PAYMENT_CAPABILITY_ERRCODE = -15105
const MIN_VIRTUAL_PAYMENT_SDK_VERSION = '2.19.2'
const MIN_IOS_WECHAT_VERSION = '8.0.68'
const MIN_IOS_SYSTEM_MAJOR_VERSION = 15
const buildVirtualPaymentLastFailKey = orderKey => `virtualPaymentLastFail_${orderKey || 'unknown'}`
const buildTempUnifyDataKey = orderKey => `tempUnifyData_${orderKey}`

const compareVersion = (left, right) => {
  const leftParts = String(left || '').split('.')
  const rightParts = String(right || '').split('.')
  const len = Math.max(leftParts.length, rightParts.length)
  for (let i = 0; i < len; i += 1) {
    const a = Number(leftParts[i] || 0)
    const b = Number(rightParts[i] || 0)
    if (a > b) return 1
    if (a < b) return -1
  }
  return 0
}

const parseIosMajorVersion = system => {
  const matched = String(system || '').match(/iOS\s+(\d+)/i)
  return matched ? Number(matched[1]) : null
}

const getVirtualPaymentRuntimeContext = () => {
  const context = {
    envVersion: '',
    appId: '',
    platform: '',
    scene: null,
    sdkVersion: '',
    wechatVersion: '',
    system: ''
  }
  try {
    if (typeof wx !== 'undefined' && typeof wx.getAccountInfoSync === 'function') {
      const accountInfo = wx.getAccountInfoSync() || {}
      context.envVersion = accountInfo.miniProgram && accountInfo.miniProgram.envVersion ? accountInfo.miniProgram.envVersion : ''
      context.appId = accountInfo.miniProgram && accountInfo.miniProgram.appId ? accountInfo.miniProgram.appId : ''
    }
  } catch (err) {
    // best effort only
  }
  try {
    if (typeof wx !== 'undefined' && typeof wx.getSystemInfoSync === 'function') {
      const systemInfo = wx.getSystemInfoSync() || {}
      context.platform = systemInfo.platform || ''
      context.sdkVersion = systemInfo.SDKVersion || ''
      context.wechatVersion = systemInfo.version || ''
      context.system = systemInfo.system || ''
    }
  } catch (err) {
    // best effort only
  }
  try {
    if (typeof wx !== 'undefined' && typeof wx.getLaunchOptionsSync === 'function') {
      const launchOptions = wx.getLaunchOptionsSync() || {}
      context.scene = launchOptions.scene !== undefined && launchOptions.scene !== null
        ? Number(launchOptions.scene)
        : null
    }
  } catch (err) {
    // best effort only
  }
  return context
}

const buildAutomatorCaptureOnlyError = (rawMessage, runtimeContext = null) => {
  const message = '当前微信开发者工具仍处于 automator 抓参态，请彻底退出开发者工具并关闭相关自动化进程后，用普通方式重新打开项目再试'
  return {
    errMsg: rawMessage || 'requestVirtualPayment:fail automator capture only',
    message,
    errCode: VIRTUAL_PAYMENT_AUTOMATOR_ERRCODE,
    rawMessage: rawMessage || '',
    runtimeContext
  }
}

const buildSandboxReleaseError = runtimeContext => {
  const message = '当前运行的是正式版小程序，微信虚拟支付沙箱仅支持开发版或体验版，请切换后重试'
  return {
    errMsg: message,
    message,
    errCode: VIRTUAL_PAYMENT_SANDBOX_RELEASE_ERRCODE,
    rawMessage: '',
    runtimeContext
  }
}

const buildDevtoolsPreviewTimeoutError = (rawMessage, runtimeContext = null) => {
  const message = '当前是在微信开发者工具预览态发起虚拟支付，微信侧已进入真实下单但该预览通道容易超时关单；请彻底退出 automator/旧会话后，改用最新体验版二维码在 Android 真机重试'
  return {
    errMsg: rawMessage || 'requestVirtualPayment:fail timeout in devtools preview',
    message,
    errCode: VIRTUAL_PAYMENT_DEVTOOLS_PREVIEW_TIMEOUT_ERRCODE,
    rawMessage: rawMessage || '',
    runtimeContext
  }
}

const buildTrialAccessDeniedError = (rawMessage, runtimeContext = null) => {
  // errno 102 access denied 是微信侧 JSAPI 层权限/类目/签约校验失败，不是签名错误。
  // 虚拟支付场景下最常见根因：
  //   1. 小程序服务类目未包含虚拟商品相关类目（category not permitted）
  //   2. 虚拟支付独立签约未完成（普通微信支付商户号 ≠ 虚拟支付签约）
  //   3. offerId 与当前 AppID 在虚拟支付后台未正确绑定
  //   4. 虚拟支付二级商户号进件未完成或被风控
  // 注意：虚拟支付不需要接入「订单发货管理」（那是普通实物微信支付的合规要求）
  const message = '微信侧返回 accessdenied (errno 102)，这是 JSAPI 层权限/类目/签约校验失败，不是签名错误。请优先核验：1) 小程序服务类目是否包含虚拟商品相关类目；2) 虚拟支付是否已完成独立签约（不是普通微信支付签约）；3) offerId 是否绑定到当前 AppID；4) 虚拟支付二级商户号是否完成进件且未受限。虚拟支付不需要接入订单发货管理。'
  return {
    errMsg: rawMessage || 'requestVirtualPayment:fail requestPayment:fail:accessdenied',
    message,
    errCode: VIRTUAL_PAYMENT_TRIAL_ACCESS_DENIED_ERRCODE,
    rawMessage: rawMessage || '',
    runtimeContext
  }
}

const buildVirtualPaymentCapabilityError = (message, runtimeContext = null, rawMessage = '') => ({
  errMsg: rawMessage || message,
  message,
  errCode: VIRTUAL_PAYMENT_CAPABILITY_ERRCODE,
  rawMessage: rawMessage || '',
  runtimeContext
})

const getVirtualPaymentCapabilityError = (runtimeContext, env) => {
  if (!runtimeContext) {
    return null
  }
  if (runtimeContext.sdkVersion && compareVersion(runtimeContext.sdkVersion, MIN_VIRTUAL_PAYMENT_SDK_VERSION) < 0) {
    return buildVirtualPaymentCapabilityError(`当前微信基础库版本过低（${runtimeContext.sdkVersion}），虚拟支付至少需要 ${MIN_VIRTUAL_PAYMENT_SDK_VERSION}`)
  }
  const system = String(runtimeContext.system || '')
  const platformName = String(runtimeContext.platform || '')
  const isIos = /ios/i.test(system)
  const isRealIosHost = isIos && platformName !== 'devtools'
  if (isRealIosHost && Number(env || 0) === 1) {
    return buildVirtualPaymentCapabilityError('iOS 端不支持虚拟支付沙箱环境，请改用 Android 真机测试，或切到现网环境后再验证', runtimeContext)
  }
  if (isRealIosHost) {
    const iosMajor = parseIosMajorVersion(system)
    if (iosMajor !== null && iosMajor < MIN_IOS_SYSTEM_MAJOR_VERSION) {
      return buildVirtualPaymentCapabilityError(`当前 iOS 版本过低（${system}），虚拟支付至少需要 iOS ${MIN_IOS_SYSTEM_MAJOR_VERSION}`, runtimeContext)
    }
    if (runtimeContext.wechatVersion && compareVersion(runtimeContext.wechatVersion, MIN_IOS_WECHAT_VERSION) < 0) {
      return buildVirtualPaymentCapabilityError(`当前微信版本过低（${runtimeContext.wechatVersion}），iOS 端虚拟支付至少需要微信 ${MIN_IOS_WECHAT_VERSION}`, runtimeContext)
    }
  }
  return null
}

const isAutomatorCaptureOnly = value => String(value || '').toLowerCase().includes('automator capture only')
const isTrialAccessDenied = (rawMessage, runtimeContext, env) => {
  if (Number(env || 0) !== 1) {
    return false
  }
  if (!runtimeContext || runtimeContext.envVersion !== 'trial') {
    return false
  }
  const normalized = String(rawMessage || '').toLowerCase()
  return normalized.includes('accessdenied') || normalized.includes('access denied')
}
const isDevtoolsPreviewTimeout = (rawMessage, res, runtimeContext, env) => {
  if (Number(env || 0) !== 1) {
    return false
  }
  if (!runtimeContext || runtimeContext.platform !== 'devtools') {
    return false
  }
  const normalized = String(rawMessage || '').toLowerCase()
  return normalized.includes('timeout')
    || normalized.includes('requestpayment:fail cancel')
    || Number(res && res.errCode) === -2
}

const isVirtualPaymentUserCancel = (rawMessage, res, runtimeContext = null) => {
  if (runtimeContext && runtimeContext.platform === 'devtools') {
    return false
  }
  const normalized = String(rawMessage || '').toLowerCase()
  return Number(res && res.errCode) === -2
    || normalized.includes('requestvirtualpayment:fail cancel')
    || normalized.includes('requestpayment:fail cancel')
    || normalized.includes('cancel')
    || normalized.includes('用户取消')
    || normalized.includes('已取消')
}

const clearTempUnifyData = orderKey => {
  if (orderKey !== null && orderKey !== undefined && orderKey !== '') {
    storage.remove(buildTempUnifyDataKey(orderKey))
  }
}

const clearVirtualPaymentLastFail = orderKey => {
  if (orderKey !== null && orderKey !== undefined && orderKey !== '') {
    storage.remove(buildVirtualPaymentLastFailKey(orderKey))
  }
}

const persistVirtualPaymentLastFail = (options, error) => {
  storage.set(buildVirtualPaymentLastFailKey(options.orderKey), {
    at: Date.now(),
    provider: 'virtual',
    orderKey: options.orderKey || null,
    outTradeNo: options.outTradeNo || options.out_trade_no || '',
    env: Number(options.env || 0),
    goodsPrice: Number(options.goods_price || 0),
    productId: options.productId || options.product_id || '',
    errCode: error && error.errCode !== undefined ? error.errCode : null,
    errMsg: error && error.errMsg ? error.errMsg : '',
    message: error && error.message ? error.message : '',
    rawMessage: error && error.rawMessage ? error.rawMessage : '',
    runtimeContext: error && error.runtimeContext ? error.runtimeContext : null
  }, 24 * 60 * 60)
}

/**
 * 发起支付请求 (用于微信小程序)
 * @param {Object} option 参数
 */
const paymentAsWxMp = option => {
  const options = {
    timeStamp: '',
    nonceStr: '',
    package: '',
    signType: '',
    paySign: '',
    ...option
  }
  return new Promise((resolve, reject) => {
    uni.requestPayment({
      provider: 'wxpay',
      timeStamp: options.timeStamp,
      nonceStr: options.nonceStr,
      package: options.package,
      signType: options.signType,
      paySign: options.paySign,
      success(res) {
        const option = {
          isRequireQuery: true, // 是否需要主动查单
          outTradeNo: options.out_trade_no, // 交易订单号
          method: 'wechat'
        }
        resolve({ res, option })
      },
      fail: res => reject(res)
    })
  })
}

/**
 * 发起虚拟支付请求 (微信小程序)
 * @param {Object} option 参数
 */
const paymentAsWxMpVirtual = option => {
  const options = {
    orderKey: null,
    mode: 'short_series_goods',
    signData: '',
    paySig: '',
    signature: '',
    outTradeNo: '',
    env: 0,
    goods_price: 0,
    ...option
  }
  return new Promise((resolve, reject) => {
    if (typeof wx === 'undefined' || typeof wx.requestVirtualPayment !== 'function') {
      const message = '当前微信基础库不支持虚拟支付，请升级微信后重试'
      const error = { errMsg: message, message, errCode: 1001, shouldSkipAutoQuery: true }
      persistVirtualPaymentLastFail(options, error)
      reject(error)
      return
    }
    const runtimeContext = getVirtualPaymentRuntimeContext()
    const capabilityError = getVirtualPaymentCapabilityError(runtimeContext, options.env)
    if (capabilityError) {
      const error = { ...capabilityError, shouldSkipAutoQuery: true }
      persistVirtualPaymentLastFail(options, error)
      reject(error)
      return
    }
    if (Number(options.env || 0) === 1 && runtimeContext.envVersion === 'release') {
      const error = { ...buildSandboxReleaseError(runtimeContext), shouldSkipAutoQuery: true }
      persistVirtualPaymentLastFail(options, error)
      reject(error)
      return
    }
    clearVirtualPaymentLastFail(options.orderKey)
    clearTempUnifyData(options.orderKey)
    storage.set(buildTempUnifyDataKey(options.orderKey), {
      method: PayMethodEnum.WECHAT.value,
      outTradeNo: options.outTradeNo || options.out_trade_no
    }, 60 * 60)
    wx.requestVirtualPayment({
      signData: options.signData,
      paySig: options.paySig,
      signature: options.signature,
      mode: options.mode,
      success (res) {
        clearVirtualPaymentLastFail(options.orderKey)
        resolve({
          res,
          option: {
            isRequireQuery: true,
            outTradeNo: options.outTradeNo || options.out_trade_no,
            method: PayMethodEnum.WECHAT.value
          }
        })
      },
      fail: res => {
        const rawMessage = res && (res.errMsg || res.message)
        if (isVirtualPaymentUserCancel(rawMessage, res, runtimeContext)) {
          clearTempUnifyData(options.orderKey)
          clearVirtualPaymentLastFail(options.orderKey)
          reject({
            ...(res || {}),
            runtimeContext,
            isUserCancel: true,
            shouldSkipAutoQuery: true,
            message: '已取消支付'
          })
          return
        }
        if (isAutomatorCaptureOnly(rawMessage)) {
          const error = {
            ...res,
            ...buildAutomatorCaptureOnlyError(rawMessage, runtimeContext),
            shouldSkipAutoQuery: true
          }
          persistVirtualPaymentLastFail(options, error)
          reject(error)
          return
        }
        if (isTrialAccessDenied(rawMessage, runtimeContext, options.env)) {
          const error = {
            ...(res || {}),
            ...buildTrialAccessDeniedError(rawMessage, runtimeContext),
            shouldSkipAutoQuery: true
          }
          persistVirtualPaymentLastFail(options, error)
          reject(error)
          return
        }
        if (isDevtoolsPreviewTimeout(rawMessage, res, runtimeContext, options.env)) {
          const error = {
            ...(res || {}),
            ...buildDevtoolsPreviewTimeoutError(rawMessage, runtimeContext)
          }
          persistVirtualPaymentLastFail(options, error)
          reject(error)
          return
        }
        const error = {
          ...(res || {}),
          runtimeContext
        }
        persistVirtualPaymentLastFail(options, error)
        reject(error)
      }
    })
  })
}

/**
 * 发起支付请求 (用于H5)
 * @param {Object} option 参数
 */
const paymentAsH5 = option => {
  const options = { orderKey: null, mweb_url: '', h5_url: '', ...option }
  // 记录下单的信息
  storage.set('tempUnifyData_' + options.orderKey, {
    method: PayMethodEnum.WECHAT.value,
    outTradeNo: options.out_trade_no
  }, 60 * 60)
  // 跳转到微信支付页
  return new Promise((resolve, reject) => {
    const url = options.mweb_url || options.h5_url
    if (url) {
      window.location.href = url
    }
  })
}

/**
 * 发起支付请求 (用于APP)
 * @param {Object} option 参数
 */
const paymentAsApp = options => {
  return new Promise((resolve, reject) => {
    uni.requestPayment({
      provider: 'wxpay',
      orderInfo: {
        partnerid: options.partnerid,
        appid: options.appid,
        package: 'Sign=WXPay',
        noncestr: options.noncestr,
        sign: options.sign,
        prepayid: options.prepayid,
        timestamp: options.timestamp
      },
      success(res) {
        // isRequireQuery 是否需要主动查单
        // outTradeNo 交易订单号
        resolve({ res, option: { isRequireQuery: true, outTradeNo: options.out_trade_no, method: 'wechat' } })
      },
      fail: res => reject(res)
    })
  })
}

/**
 * 统一下单API
 */
export const payment = (option) => {
  if (platform === ClientEnum.MP_WEIXIN.value && option && option.provider === 'virtual') {
    return paymentAsWxMpVirtual(option)
  }
  const events = {
    [ClientEnum.H5.value]: paymentAsH5,
    [ClientEnum.MP_WEIXIN.value]: paymentAsWxMp,
    [ClientEnum.APP.value]: paymentAsApp
  }
  return events[platform](option)
}

/**
 * 统一下单API需要的扩展数据
 */
export const extraAsUnify = () => {
  if (platform === ClientEnum.MP_WEIXIN.value && typeof wx !== 'undefined' && typeof wx.login === 'function') {
    const runtimeContext = getVirtualPaymentRuntimeContext()
    return new Promise((resolve, reject) => {
      wx.login({
        success({ code }) {
          resolve(code ? { loginCode: code, runtimeContext } : { runtimeContext })
        },
        fail(err) {
          reject(err)
        }
      })
    })
  }
  return {}
}
