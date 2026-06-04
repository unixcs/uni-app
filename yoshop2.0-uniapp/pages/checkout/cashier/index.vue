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

  // H5端支付下单时的数据
  // 用于从第三方支付页返回到收银台页面后拿到下单数据
  const getTempUnifyData = orderKey => {
    // if (window.performance && window.performance.navigation.type == 2) {
    const tempUnifyData = storage.get('tempUnifyData_' + orderKey)
    if (tempUnifyData) {
      storage.remove('tempUnifyData_' + orderKey)
      return tempUnifyData
    }
    // }
    return null
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

      // 获取收银台信息
      getCashierInfo() {
        const app = this
        app.isLoading = true
        CashierApi.orderInfo(app.orderId, { client: app.platform })
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
              return
            }
            app.isLoading = false
            app.setDefaultPayType()
            app.checkOrderPayStatus()
            // #ifdef H5
            // 判断当前页面来源于浏览器返回
            this.performance()
            // #endif
          })
      },

      // 设置默认的支付方式
      setDefaultPayType() {
        const app = this
        if (app.disabled) return
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
          app.$toast('恭喜您，订单已付款成功')
          app.onSuccessNav()
        }
      },

      // 选择支付方式
      handleSelectPayType(index) {
        this.curPaymentItem = this.methods[index]
      },

      // 判断当前页面来源于浏览器返回并提示手动查单
      // #ifdef H5
      performance() {
        const app = this
        // 判断订单状态, 异步回调会将订单状态变为已支付, 那么就不需要让用户手动查单了
        if (app.order.pay_status == PayStatusEnum.PENDING.value) {
          const performanceData = getTempUnifyData(app.orderId)
          if (performanceData) {
            app.tempUnifyData = performanceData
            app.showConfirmModal = true
          }
        }
      },
      // #endif

      // 确认支付
      handleSubmit() {
        const app = this
        // 判断是否选择了支付方式
        if (!app.curPaymentItem) {
          app.$toast('您还没有选择支付方式')
          return
        }
        // 按钮禁用
        if (app.disabled) return
        app.disabled = true
        // 提交到后端API
        CashierApi.orderPay(app.orderId, {
            method: app.curPaymentItem.method,
            client: app.platform,
            extra: app.getExtraAsUnify(app.curPaymentItem.method)
          })
          .then(result => app.onSubmitCallback(result))
          .finally(err => setTimeout(() => app.disabled = false, 10))
      },

      // 获取第三方支付的扩展参数
      getExtraAsUnify(method) {
        if (method === PayMethodEnum.ALIPAY.value) {
          return Alipay.extraAsUnify()
        }
        if (method === PayMethodEnum.WECHAT.value) {
          return Wechat.extraAsUnify()
        }
        return {}
      },

      // 订单提交成功后回调
      onSubmitCallback(result) {
        const app = this
        const method = app.curPaymentItem.method
        const paymentData = result.data.payment
        // 余额支付
        if (method === PayMethodEnum.BALANCE.value) {
          app.onShowSuccess(result)
        }
        // 发起支付宝支付
        if (method === PayMethodEnum.ALIPAY.value) {
          console.log('paymentData', paymentData)
          Alipay.payment({ orderKey: app.orderId, ...paymentData })
            .then(res => app.onPaySuccess(res))
            .catch(err => app.onPayFail(err))
        }
        // 发起微信支付
        if (method === PayMethodEnum.WECHAT.value) {
          console.log('paymentData', paymentData)
          Wechat.payment({ orderKey: app.orderId, ...paymentData })
            .then(res => app.onPaySuccess(res))
            .catch(err => app.onPayFail(err))
        }
      },

      // 订单支付成功的回调方法
      // 这里只是前端支付api返回结果success,实际订单是否支付成功 以后端的查单和异步通知为准
      onPaySuccess({ res, option: { isRequireQuery, outTradeNo, method } }) {
        const app = this
        // 判断是否需要主动查单
        // isRequireQuery为true代表需要主动查单
        if (isRequireQuery) {
          app.onTradeQuery(outTradeNo, method)
          return true
        }
        this.onShowSuccess(res)
      },

      // 显示支付成功信息并页面跳转
      onShowSuccess({ message }) {
        this.$toast(message || '订单支付成功')
        this.onSuccessNav()
      },

      // 订单支付失败
      onPayFail(err) {
        console.log('onPayFail', err)
        const errMsg = err.message || '订单未支付'
        this.$error(errMsg)
      },

      // 已完成支付按钮事件: 请求后端查单
      onTradeQuery(outTradeNo, method) {
        const app = this
        // 交易查询
        // 查询第三方支付订单是否付款成功
        CashierApi.tradeQuery({ outTradeNo, method, client: app.platform })
          .then(result => result.data.isPay ? app.onShowSuccess(result) : app.onPayFail(result))
          .finally(() => app.showConfirmModal = false)
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
