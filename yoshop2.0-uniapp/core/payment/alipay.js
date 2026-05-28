import platform from '@/core/platform'
import storage from '@/utils/storage'
import ClientEnum from '@/common/enum/Client'
import { PayMethodEnum } from '@/common/enum/payment'

/**
 * 发起支付请求 (用于H5)
 * @param {Object} option 参数
 */
const paymentAsH5 = option => {
  const options = { formHtml: '', ...option }
  // 记录下单的信息
  storage.set('tempUnifyData_' + options.orderKey, {
    method: PayMethodEnum.ALIPAY.value,
    outTradeNo: options.out_trade_no
  }, 60 * 60)
  // 跳转到支付宝支付页
  return new Promise((resolve, reject) => {
    // console.log(options.formHtml)
    if (options.formHtml) {
      const div = document.createElement('div')
      div.innerHTML = options.formHtml
      document.body.appendChild(div)
      document.forms[0].submit()
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
      provider: 'alipay',
      orderInfo: options.orderInfo,
      success(res) {
        // isRequireQuery 是否需要主动查单
        // outTradeNo 交易订单号
        const option = {
          isRequireQuery: true,
          outTradeNo: options.out_trade_no,
          method: 'alipay'
        }
        resolve({ res, option })
      },
      fail: res => reject(res)
    })
  })
}

// 获取支付完成后跳转的url
// #ifdef H5
const returnUrl = () => {
  return window.location.href
}
// #endif

/**
 * 统一下单API
 */
export const payment = (option) => {
  const events = {
    [ClientEnum.H5.value]: paymentAsH5,
    [ClientEnum.APP.value]: paymentAsApp
  }
  return events[platform](option)
}

/**
 * 统一下单API需要的扩展数据
 */
export const extraAsUnify = () => {
  const extra = {}
  // #ifdef H5
  extra.returnUrl = returnUrl()
  // #endif
  return extra
}
