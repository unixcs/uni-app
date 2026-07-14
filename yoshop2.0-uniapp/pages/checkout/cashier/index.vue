<template>
  <view v-if="!isLoading" class="container" :style="appThemeStyle">
    <!-- 订单信息 -->
    <view class="order-info">
      <!-- 支付剩余时间 -->
      <view v-if="order.showExpiration" class="order-countdown">
        <text class="m-r-6">剩余时间</text>
        <count-down :date="order.expirationTime" separator="zh" theme="text" textColor="#666666" customNumColor="#666666" />
      </view>
      <!-- 付款金额 -->
      <view class="order-amount">
        <text class="unit">￥</text>
        <text class="amount">{{ order.pay_price }}</text>
      </view>
    </view>
    <!-- 支付方式 -->
    <view class="payment-method">
      <view v-for="(item, index) in methods" :key="index" class="pay-item dis-flex flex-x-between" @click="handleSelectPayType(index)">
        <view class="item-left dis-flex flex-y-center">
          <view class="item-left_icon" :class="[item.method]">
            <text class="iconfont" :class="[PayMethodIconEnum[item.method]]"></text>
          </view>
          <view class="item-left_text">
            <text>{{ PayMethodEnum[item.method].name }}</text>
          </view>
          <view v-if="item.method === PayMethodEnum.BALANCE.value" class="user-balance">
            <text>(可用￥{{ personal.balance }}元)</text>
          </view>
        </view>
        <view class="item-right col-m" v-if="curPaymentItem && curPaymentItem.method == item.method">
          <text class="iconfont icon-check"></text>
        </view>
      </view>
    </view>
    <!-- 确认按钮 -->
    <view class="footer-fixed">
      <view class="btn-wrapper">
        <view class="btn-item btn-item-main" :class="{ disabled }" @click="handleSubmit()">确认支付</view>
      </view>
    </view>
    <!-- 支付确认弹窗 -->
    <!-- #ifdef H5 -->
    <u-modal v-if="tempUnifyData" v-model="showConfirmModal" title="支付确认" show-cancel-button confirm-text="已完成支付"
      :confirm-color="appTheme.mainBg" negative-top="100" :asyncClose="true"
      @confirm="onTradeQuery(tempUnifyData.outTradeNo, tempUnifyData.method)">
      <view class="modal-content">
        <text>请在{{ PayMethodClientNameEnum[tempUnifyData.method] }}内完成支付，如果您已经支付成功，请点击“已完成支付”按钮</text>
      </view>
    </u-modal>
    <!-- #endif -->
  </view>
</template>

<script>
  import storage from '@/utils/storage'
  import { inArray, urlEncode } from '@/utils/util'
  import { Alipay, Wechat } from '@/core/payment'
  import CountDown from '@/components/countdown'
  import { PayMethodEnum } from '@/common/enum/payment'
  import { PayStatusEnum } from '@/common/enum/order'
  import * as CashierApi from '@/api/cashier'
  import { isH5, isMpWeixin, isWeixinOfficial } from '@/core/platform'

  // 支付方式对应的图标
  const PayMethodIconEnum = {
    [PayMethodEnum.WECHAT.value]: 'icon-wechat-pay',
    [PayMethodEnum.ALIPAY.value]: 'icon-alipay',
    [PayMethodEnum.BALANCE.value]: 'icon-balance-pay'
  }

  // 支付方式的终端名称
  const PayMethodClientNameEnum = {
    [PayMethodEnum.WECHAT.value]: '微信',
    [PayMethodEnum.ALIPAY.value]: '支付宝'
  }

  const PaymentPhaseEnum = Object.freeze({
    IDLE: 'idle',
    CREATING: 'creating',
    CASHIER_OPEN: 'cashier_open',
    CONFIRMING: 'confirming',
    AWAITING_CONFIRMATION: 'awaiting_confirmation',
    SUCCESS: 'success',
    CANCELLED: 'cancelled',
    FAILED: 'failed'
  })
  const TRADE_QUERY_RETRY_DELAYS = [400, 800, 1200, 1600, 2000]
  const wait = delay => new Promise(resolve => setTimeout(resolve, delay))

  // 支付下单时的临时数据
  // 用于从第三方支付页返回到收银台页面后拿到下单数据，或用于小程序支付回调丢失后的查单恢复
  const getTempUnifyData = orderKey => {
    const key = 'tempUnifyData_' + orderKey
    const tempUnifyData = storage.get(key)
    if (tempUnifyData) {
      storage.remove(key)
      return tempUnifyData
    }
    return null
  }
  const setTempUnifyData = (orderKey, data) => storage.set('tempUnifyData_' + orderKey, data, 60 * 60)
  const clearTempUnifyData = orderKey => storage.remove('tempUnifyData_' + orderKey)
  const getCancelledVirtualTrade = orderKey => storage.get('cancelledVirtualTrade_' + orderKey)
  const setCancelledVirtualTrade = (orderKey, outTradeNo) => storage.set('cancelledVirtualTrade_' + orderKey, { outTradeNo }, 60 * 60)
  const clearCancelledVirtualTrade = orderKey => storage.remove('cancelledVirtualTrade_' + orderKey)
  const getVirtualPaymentLastFail = orderKey => storage.get('virtualPaymentLastFail_' + orderKey)
  const clearVirtualPaymentLastFail = orderKey => storage.remove('virtualPaymentLastFail_' + orderKey)
  const getErrorText = err => String((err && (err.message || err.errMsg)) || '').toLowerCase()
  const shouldAppendVirtualPaymentFailHint = (payload, err, context = {}) => {
    if (!payload) {
      return false
    }
    if (context.outTradeNo && payload.outTradeNo && context.outTradeNo !== payload.outTradeNo) {
      return false
    }
    if (context.phase === 'query') {
      return !!(context.outTradeNo && payload.outTradeNo === context.outTradeNo)
    }
    if (context.method === PayMethodEnum.WECHAT.value && context.provider === 'virtual') {
      return true
    }
    return getErrorText(err).includes('requestvirtualpayment')
  }
  const formatVirtualPaymentFailHint = payload => {
    if (!payload) {
      return ''
    }
    const parts = []
    if (payload.errCode !== null && payload.errCode !== undefined && payload.errCode !== '') {
      parts.push(`错误码:${payload.errCode}`)
    }
    if (payload.outTradeNo) {
      parts.push(`交易号:${payload.outTradeNo}`)
    }
    if (payload.runtimeContext && payload.runtimeContext.envVersion) {
      parts.push(`运行环境:${payload.runtimeContext.envVersion}`)
    }
    if (payload.runtimeContext && payload.runtimeContext.platform) {
      parts.push(`宿主:${payload.runtimeContext.platform}`)
    }
    if (payload.runtimeContext && payload.runtimeContext.scene !== null && payload.runtimeContext.scene !== undefined && payload.runtimeContext.scene !== '') {
      parts.push(`场景:${payload.runtimeContext.scene}`)
    }
    if (payload.runtimeContext && payload.runtimeContext.wechatVersion) {
      parts.push(`微信:${payload.runtimeContext.wechatVersion}`)
    }
    if (payload.runtimeContext && payload.runtimeContext.sdkVersion) {
      parts.push(`SDK:${payload.runtimeContext.sdkVersion}`)
    }
    return parts.length ? `（${parts.join('，')}）` : ''
  }
  const formatTradeQueryContextHint = outTradeNo => outTradeNo ? `（本次查单交易号:${outTradeNo}）` : ''
  const isVirtualPaymentUserCancel = err => {
    const errCode = Number(err && err.errCode)
    const rawText = String((err && (err.rawMessage || err.errMsg)) || '').toLowerCase()
    return !!(err && err.isUserCancel)
      || errCode === -2
      || rawText.includes('requestvirtualpayment:fail cancel')
      || rawText.includes('requestpayment:fail cancel')
  }
  const shouldAutoQueryVirtualPaymentFailure = (payload, context = {}, err = null) => {
    if (!payload || !payload.outTradeNo) {
      return false
    }
    if (context.phase !== 'payment') {
      return false
    }
    if (context.method !== PayMethodEnum.WECHAT.value || context.provider !== 'virtual') {
      return false
    }
    if ((err && err.shouldSkipAutoQuery) || isVirtualPaymentUserCancel(err)) {
      return false
    }
    return true
  }

  export default {
    components: {
      CountDown
    },
    data() {
      return {
        // 加载中
        isLoading: true,
        // 确认按钮禁用
        disabled: false,
        // 枚举类
        PayMethodEnum,
        PayMethodIconEnum,
        PayMethodClientNameEnum,
        // 当前选中的支付方式
        curPaymentItem: null,
        // 当前订单ID
        orderId: null,
        // 当前结算订单信息
        order: {},
        // 个人信息
        personal: { balance: '0.00' },
        // 当前客户端的支付方式列表（后端根据platform判断）
        methods: [],
        // 支付确认弹窗
        showConfirmModal: false,
        // 支付状态机：活动交易未到明确终态前禁止再次创建支付
        paymentPhase: PaymentPhaseEnum.IDLE,
        activeOutTradeNo: '',
        activePaymentMethod: '',
        tradeQueryPromise: null,
        successHandled: false,
        // 是否正在查单，兼容模板和旧逻辑
        isTradeQuerying: false,
        // #ifdef H5
        // 当前第三方支付信息 (临时数据, 仅用于H5端)
        tempUnifyData: { outTradeNo: '', method: '' },
        // #endif
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad({ orderId }) {
      // 记录订单ID
      this.orderId = Number(orderId)
    },

    /**
     * 生命周期函数--监听页面显示
     */
    onShow() {
      // 获取收银台信息
      this.getCashierInfo()
    },

    methods: {

      shouldHideReviewUi() {
        return isH5 || isMpWeixin || isWeixinOfficial
      },

      isPaymentLocked() {
        return [
          PaymentPhaseEnum.CREATING,
          PaymentPhaseEnum.CASHIER_OPEN,
          PaymentPhaseEnum.CONFIRMING,
          PaymentPhaseEnum.AWAITING_CONFIRMATION,
          PaymentPhaseEnum.SUCCESS
        ].includes(this.paymentPhase)
      },

      setPaymentPhase(phase) {
        this.paymentPhase = phase
        this.disabled = this.isPaymentLocked()
      },

      rememberActiveTrade(outTradeNo, method = PayMethodEnum.WECHAT.value) {
        if (!outTradeNo) {
          return
        }
        this.activeOutTradeNo = outTradeNo
        this.activePaymentMethod = method
        setTempUnifyData(this.orderId, { outTradeNo, method })
      },

      clearActiveTrade() {
        this.activeOutTradeNo = ''
        this.activePaymentMethod = ''
        clearTempUnifyData(this.orderId)
      },

      // 获取收银台信息
      getCashierInfo() {
        const app = this
        app.isLoading = true
        return CashierApi.orderInfo(app.orderId, { client: app.platform })
          .then(result => {
            app.order = result.data.order
            app.personal = result.data.personal
            app.methods = app.shouldHideReviewUi()
              ? result.data.paymentMethods.filter(item => item.method === PayMethodEnum.WECHAT.value)
              : result.data.paymentMethods
            if (app.shouldHideReviewUi() && !app.methods.length) {
              app.isLoading = false
              app.$toast('当前仅支持微信支付')
              setTimeout(() => uni.navigateBack({ delta: 1 }), 1200)
              return result
            }
            app.isLoading = false
            app.setDefaultPayType()
            if (!app.checkOrderPayStatus()) {
              app.performance()
            }
            return result
          })
          .catch(err => {
            app.isLoading = false
            throw err
          })
      },

      // 设置默认的支付方式
      setDefaultPayType() {
        const app = this
        if (app.isPaymentLocked()) return
        if (app.curPaymentItem) return
        const defaultIndex = app.shouldHideReviewUi()
          ? app.methods.findIndex(item => item.method === PayMethodEnum.WECHAT.value)
          : app.methods.findIndex(item => item.is_default == true)
        defaultIndex > -1 && app.handleSelectPayType(defaultIndex)
      },

      // 判断当前订单是否为已支付
      checkOrderPayStatus() {
        const app = this
        if (app.order.pay_status == PayStatusEnum.SUCCESS.value) {
          app.onShowSuccess({ message: '恭喜您，订单已付款成功' })
          return true
        }
        return false
      },

      // 选择支付方式
      handleSelectPayType(index) {
        if (this.isPaymentLocked()) {
          return
        }
        this.curPaymentItem = this.methods[index]
      },

      // 尝试恢复上一次支付中的查单状态
      performance() {
        const app = this
        if (app.order.pay_status != PayStatusEnum.PENDING.value) {
          return
        }
        // 原生收银台活动期间，onShow 只是生命周期信号，不能抢跑并消费交易恢复状态
        if ([PaymentPhaseEnum.CREATING, PaymentPhaseEnum.CASHIER_OPEN, PaymentPhaseEnum.CONFIRMING].includes(app.paymentPhase)) {
          return
        }
        const performanceData = getTempUnifyData(app.orderId)
        if (!performanceData) {
          return
        }
        // #ifdef H5
        app.tempUnifyData = performanceData
        app.showConfirmModal = true
        // #endif
        // #ifndef H5
        app.rememberActiveTrade(performanceData.outTradeNo, performanceData.method)
        app.onTradeQuery(performanceData.outTradeNo, performanceData.method)
        // #endif
      },

      // 确认支付
      handleSubmit() {
        const app = this
        if (!app.curPaymentItem) {
          app.$toast('您还没有选择支付方式')
          return Promise.resolve(false)
        }
        if (app.isPaymentLocked()) {
          return app.tradeQueryPromise || Promise.resolve(false)
        }
        app.successHandled = false
        app.setPaymentPhase(PaymentPhaseEnum.CREATING)
        clearTempUnifyData(app.orderId)
        clearVirtualPaymentLastFail(app.orderId)
        return Promise.resolve(app.getExtraAsUnify(app.curPaymentItem.method))
          .then(extra => CashierApi.orderPay(app.orderId, {
            method: app.curPaymentItem.method,
            client: app.platform,
            extra
          }))
          .then(result => app.onSubmitCallback(result))
          .catch(err => app.onPayFail(err, { phase: 'create', method: app.curPaymentItem.method }))
          .finally(() => {
            app.disabled = app.isPaymentLocked()
          })
      },

      // 获取第三方支付的扩展参数
      getExtraAsUnify(method) {
        if (method === PayMethodEnum.ALIPAY.value) {
          return Alipay.extraAsUnify()
        }
        if (method === PayMethodEnum.WECHAT.value) {
          return Promise.resolve(Wechat.extraAsUnify()).then(extra => {
            const cancelledTrade = getCancelledVirtualTrade(this.orderId)
            return {
              ...(extra || {}),
              previousCancelledOutTradeNo: cancelledTrade ? cancelledTrade.outTradeNo : ''
            }
          })
        }
        return {}
      },

      // 订单提交成功后回调
      onSubmitCallback(result) {
        const app = this
        const method = app.curPaymentItem.method
        const paymentData = result.data.payment || {}
        const paymentState = paymentData.state || 'created'
        const outTradeNo = paymentData.outTradeNo || paymentData.out_trade_no || ''

        if (paymentState === 'paid') {
          clearCancelledVirtualTrade(app.orderId)
          return Promise.resolve(app.onShowSuccess(result))
        }
        if (paymentState === 'confirming') {
          if (!outTradeNo) {
            app.setPaymentPhase(PaymentPhaseEnum.AWAITING_CONFIRMATION)
            app.$toast(paymentData.message || result.message || '支付请求处理中，请稍后返回本页查看')
            return Promise.resolve(result)
          }
          app.rememberActiveTrade(outTradeNo, method)
          return app.onTradeQuery(outTradeNo, method)
        }
        if (paymentState === 'created') {
          clearCancelledVirtualTrade(app.orderId)
        }
        if (method === PayMethodEnum.BALANCE.value) {
          return Promise.resolve(app.onShowSuccess(result))
        }
        if (method === PayMethodEnum.ALIPAY.value) {
          app.setPaymentPhase(PaymentPhaseEnum.CASHIER_OPEN)
          return Alipay.payment({ orderKey: app.orderId, ...paymentData })
            .then(res => app.onPaySuccess(res))
            .catch(err => app.onPayFail(err, { phase: 'payment', method, provider: paymentData.provider || '', outTradeNo }))
        }
        if (method === PayMethodEnum.WECHAT.value) {
          app.rememberActiveTrade(outTradeNo, method)
          app.setPaymentPhase(PaymentPhaseEnum.CASHIER_OPEN)
          return Wechat.payment({ orderKey: app.orderId, ...paymentData })
            .then(res => app.onPaySuccess(res))
            .catch(err => app.onPayFail(err, {
              phase: 'payment',
              method,
              provider: paymentData.provider || '',
              outTradeNo
            }))
        }
        return Promise.resolve(result)
      },

      // 原生支付 success 只是收口起点，订单事实以后端查单/通知为准
      onPaySuccess({ res, option: { isRequireQuery, outTradeNo, method } }) {
        const app = this
        if (isRequireQuery) {
          app.rememberActiveTrade(outTradeNo, method)
          return app.onTradeQuery(outTradeNo, method)
        }
        app.onShowSuccess(res)
        return Promise.resolve(res)
      },

      // 显示支付成功信息并页面跳转（幂等）
      onShowSuccess({ message } = {}) {
        if (this.successHandled) {
          return true
        }
        this.successHandled = true
        this.setPaymentPhase(PaymentPhaseEnum.SUCCESS)
        this.clearActiveTrade()
        clearVirtualPaymentLastFail(this.orderId)
        this.$toast(message || '订单支付成功')
        this.onSuccessNav()
        return true
      },

      // 订单支付失败
      onPayFail(err = {}, context = {}) {
        const app = this
        const lastVirtualFail = getVirtualPaymentLastFail(app.orderId)
        if (isVirtualPaymentUserCancel(err)) {
          const cancelledOutTradeNo = context.outTradeNo || app.activeOutTradeNo || (lastVirtualFail && lastVirtualFail.outTradeNo) || ''
          if (cancelledOutTradeNo) {
            setCancelledVirtualTrade(app.orderId, cancelledOutTradeNo)
          }
          app.clearActiveTrade()
          clearVirtualPaymentLastFail(app.orderId)
          app.setPaymentPhase(PaymentPhaseEnum.CANCELLED)
          app.$toast('已取消支付')
          app.setPaymentPhase(PaymentPhaseEnum.IDLE)
          setTimeout(() => app.getCashierInfo().catch(() => {}), 80)
          return Promise.resolve(false)
        }
        if (err && err.shouldSkipAutoQuery) {
          const abortedOutTradeNo = context.outTradeNo || app.activeOutTradeNo || (lastVirtualFail && lastVirtualFail.outTradeNo) || ''
          if (abortedOutTradeNo) {
            // 客户端已明确未进入可付款收银台；下次点击仍由后端查单验证后才允许重建。
            setCancelledVirtualTrade(app.orderId, abortedOutTradeNo)
          }
          app.clearActiveTrade()
          const hint = shouldAppendVirtualPaymentFailHint(lastVirtualFail, err, context)
            ? formatVirtualPaymentFailHint(lastVirtualFail)
            : ''
          app.setPaymentPhase(PaymentPhaseEnum.FAILED)
          app.$error((err.message || err.errMsg || '支付能力暂不可用') + hint)
          app.setPaymentPhase(PaymentPhaseEnum.IDLE)
          return Promise.resolve(false)
        }
        if (shouldAutoQueryVirtualPaymentFailure(lastVirtualFail, context, err)) {
          return app.resolveVirtualPaymentFailure(err, context, lastVirtualFail)
        }
        const hint = shouldAppendVirtualPaymentFailHint(lastVirtualFail, err, context)
          ? formatVirtualPaymentFailHint(lastVirtualFail)
          : ''
        const errMsg = (err.message || err.errMsg || '订单未支付') + hint
        app.setPaymentPhase(PaymentPhaseEnum.FAILED)
        app.$error(errMsg)
        app.setPaymentPhase(PaymentPhaseEnum.IDLE)
        return Promise.resolve(false)
      },

      resolveVirtualPaymentFailure(err, context, lastVirtualFail) {
        const app = this
        const outTradeNo = context.outTradeNo || (lastVirtualFail && lastVirtualFail.outTradeNo) || app.activeOutTradeNo || ''
        if (!outTradeNo) {
          return app.onPayFail(err, { ...context, phase: 'payment-fallback' })
        }
        app.rememberActiveTrade(outTradeNo, PayMethodEnum.WECHAT.value)
        return app.onTradeQuery(outTradeNo, PayMethodEnum.WECHAT.value, { fallbackError: err })
      },

      // 已完成支付按钮、原生 success、onShow 恢复统一进入本方法
      onTradeQuery(outTradeNo, method, options = {}) {
        const app = this
        if (!outTradeNo) {
          return app.onPayFail({ message: '缺少支付交易号，无法确认支付结果' }, { phase: 'query', method })
        }
        if (app.tradeQueryPromise && app.activeOutTradeNo === outTradeNo) {
          return app.tradeQueryPromise
        }
        app.rememberActiveTrade(outTradeNo, method)
        app.setPaymentPhase(PaymentPhaseEnum.CONFIRMING)
        app.isTradeQuerying = true

        const queryOnce = attempt => CashierApi.tradeQuery({ outTradeNo, method, client: app.platform })
          .then(result => {
            if (result.data.isPay) {
              app.onShowSuccess(result)
              return result
            }
            if (result.data && result.data.isPending) {
              if (attempt < TRADE_QUERY_RETRY_DELAYS.length) {
                return wait(TRADE_QUERY_RETRY_DELAYS[attempt]).then(() => queryOnce(attempt + 1))
              }
              app.rememberActiveTrade(outTradeNo, method)
              app.setPaymentPhase(PaymentPhaseEnum.AWAITING_CONFIRMATION)
              app.$toast(result.message || '支付结果确认中，请稍后返回本页查看')
              return result
            }
            app.clearActiveTrade()
            app.setPaymentPhase(PaymentPhaseEnum.FAILED)
            const latestFail = getVirtualPaymentLastFail(app.orderId)
            const hint = shouldAppendVirtualPaymentFailHint(latestFail, result, {
              phase: 'query', method, outTradeNo
            }) ? formatVirtualPaymentFailHint(latestFail) : ''
            app.$error((result.message || (options.fallbackError && options.fallbackError.message) || '订单未支付') + formatTradeQueryContextHint(outTradeNo) + hint)
            app.setPaymentPhase(PaymentPhaseEnum.IDLE)
            return result
          })
          .catch(queryErr => {
            app.rememberActiveTrade(outTradeNo, method)
            app.setPaymentPhase(PaymentPhaseEnum.AWAITING_CONFIRMATION)
            const latestFail = getVirtualPaymentLastFail(app.orderId)
            const hint = shouldAppendVirtualPaymentFailHint(latestFail, queryErr, {
              phase: 'query', method, outTradeNo
            }) ? formatVirtualPaymentFailHint(latestFail) : ''
            app.$error((queryErr.message || queryErr.errMsg || (options.fallbackError && options.fallbackError.message) || '支付结果暂时无法确认') + formatTradeQueryContextHint(outTradeNo) + hint)
            return { data: { isPay: false, isPending: true }, message: queryErr.message || queryErr.errMsg || '' }
          })

        const queryPromise = queryOnce(0).finally(() => {
          if (app.tradeQueryPromise === queryPromise) {
            app.tradeQueryPromise = null
          }
          app.isTradeQuerying = false
          app.showConfirmModal = false
          app.disabled = app.isPaymentLocked()
        })
        app.tradeQueryPromise = queryPromise
        return queryPromise
      },

      // 支付成功后的跳转
      onSuccessNav() {
        // 相应全局事件订阅: 刷新上级页面数据
        uni.$emit('syncRefresh', true)
        // 获取上级页面
        const pages = getCurrentPages()
        const lastPage = pages.length < 2 ? null : pages[pages.length - 2]
        const backRoutes = [
          'pages/order/index',
          'pages/order/detail'
        ]
        setTimeout(() => {
          if (lastPage && inArray(lastPage.route, backRoutes)) {
            uni.navigateBack()
          } else {
            this.$navTo('pages/order/index', {}, 'redirectTo')
          }
        }, 1200)
      },

    }
  }
</script>

<style>
  page {
    background: #F4F4F4;
  }
</style>
<style lang="scss" scoped>
  .container {
    background-color: #F4F4F4;
  }

  // 订单信息
  .order-info {
    padding: 80rpx 0;
    text-align: center;

    .order-countdown {
      display: flex;
      justify-content: center;
      font-size: 26rpx;
      color: #666666;
      margin-bottom: 20rpx;
    }

    .order-amount {
      margin: 0 auto;
      max-width: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #111111;

      .unit {
        font-size: 30rpx;
        margin-bottom: -18rpx;
      }

      .amount {
        font-size: 56rpx;
      }
    }
  }

  // 支付方式
  .payment-method {
    width: 94%;
    margin: 0 auto 20rpx auto;
    padding: 0 40rpx;
    background-color: #ffffff;
    border-radius: 20rpx;

    .pay-item {
      padding: 26rpx 0;
      font-size: 28rpx;
      border-bottom: 1rpx solid rgb(248, 248, 248);

      &:last-child {
        border-bottom: none;
      }

      .item-left_icon {
        margin-right: 20rpx;
        font-size: 44rpx;

        &.wechat {
          color: #00c800;
        }

        &.alipay {
          color: #009fe8;
        }

        &.balance {
          color: #ff9700;
        }
      }

      .item-left_text {
        font-size: 28rpx;
      }

      .item-right {
        font-size: 32rpx;
      }

      .user-balance {
        margin-left: 20rpx;
        font-size: 26rpx;
      }
    }

  }


  // 支付确认弹窗
  .modal-content {
    padding: 40rpx 48rpx;
    font-size: 30rpx;
    line-height: 50rpx;
    text-align: left;
    color: #606266;
    // height: 620rpx;
    box-sizing: border-box;
  }


  // 底部操作栏
  .footer-fixed {
    position: fixed;
    bottom: var(--window-bottom);
    left: var(--window-left);
    right: var(--window-right);
    z-index: 11;
    box-shadow: 0 -4rpx 40rpx 0 rgba(151, 151, 151, 0.24);
    background: #fff;

    // 设置ios刘海屏底部横线安全区域
    padding-bottom: constant(safe-area-inset-bottom);
    padding-bottom: env(safe-area-inset-bottom);

    .btn-wrapper {
      height: 120rpx;
      display: flex;
      align-items: center;
      padding: 0 40rpx;
    }

    .btn-item {
      flex: 1;
      font-size: 28rpx;
      height: 80rpx;
      color: #fff;
      border-radius: 50rpx;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .btn-item-main {
      background: linear-gradient(to right, $main-bg, $main-bg2);
      color: $main-text;

      // 禁用按钮
      &.disabled {
        opacity: 0.6;
      }
    }

  }
</style>
