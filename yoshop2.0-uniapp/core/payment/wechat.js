import platform from '@/core/platform'
import storage from '@/utils/storage'
import ClientEnum from '@/common/enum/Client'
import { PayMethodEnum } from '@/common/enum/payment'

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
  return {}
}
