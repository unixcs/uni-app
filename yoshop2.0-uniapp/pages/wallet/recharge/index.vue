<template>
  <view v-if="personal.user_id" class="container" :style="appThemeStyle">
    <view class="account-panel dis-flex flex-y-center">
      <view class="panel-lable">
        <text>账户余额</text>
      </view>
      <view class="panel-balance flex-box">
        <text>￥{{ personal.balance }}</text>
      </view>
    </view>
    <view class="recharge-panel">
      <view class="recharge-label">
        <text>充值金额</text>
      </view>
      <view class="recharge-plan clearfix">
        <block v-for="(item, index) in planList" :key="index">
          <view class="recharge-plan_item" :class="{ active: selectedPlanId == item.plan_id }" @click="onSelectPlan(item.plan_id)">
            <view class="plan_money">
              <text>{{ item.money }}</text>
            </view>
            <view class="plan_gift">
              <text>送{{ item.gift_money }}</text>
            </view>
          </view>
        </block>
      </view>
      <!-- 手动充值输入框 -->
      <view class="recharge-input" v-if="setting.is_custom == 1">
        <input class="input" type="digit" placeholder="可输入自定义充值金额" v-model="inputValue" @input="onChangeMoney" />
      </view>

      <!-- 支付方式 -->
      <view class="recharge-label m-top60">
        <text>支付方式</text>
      </view>
      <view class="payment-method">
        <view v-for="(item, index) in methods" :key="index" class="pay-item dis-flex flex-x-between" @click="handleSelectPayType(index)">
          <view class="item-left dis-flex flex-y-center">
            <view class="item-left_icon" :class="[item.method]">
              <text class="iconfont" :class="[PayMethodIconEnum[item.method]]"></text>
            </view>
            <view class="item-left_text">
              <text>{{ PayMethodEnum[item.method].name }}</text>
            </view>
          </view>
          <view class="item-right col-m" v-if="curPaymentItem && curPaymentItem.method == item.method">
            <text class="iconfont icon-check"></text>
          </view>
        </view>
      </view>

      <!-- 确认按钮 -->
      <view class="recharge-submit btn-submit">
        <form @submit="onSubmit">
          <button class="button" formType="submit" :disabled="disabled">立即充值</button>
        </form>
      </view>
    </view>
    <!-- 充值描述 -->
    <view class="describe-panel">
      <view class="recharge-label">
        <text>充值说明</text>
      </view>
      <view class="content">
        <text space="ensp">{{ setting.describe }}</text>
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
  import * as RechargeApi from '@/api/recharge'
  import { PayMethodEnum } from '@/common/enum/payment'
  import { inArray, urlEncode } from '@/utils/util'
  import { Alipay, Wechat } from '@/core/payment'

  // 支付方式对应的图标
  const PayMethodIconEnum = {
    [PayMethodEnum.WECHAT.value]: 'icon-wechat-pay',
    [PayMethodEnum.ALIPAY.value]: 'icon-alipay',
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
    data() {
      return {
        // 正在加载
        isLoading: true,
        // 按钮禁用
        disabled: false,
        // 枚举类
        PayMethodEnum,
        PayMethodIconEnum,
        PayMethodClientNameEnum,
        // 个人信息
        personal: { balance: '0.00' },
        // 充值设置
        setting: {},
        // 充值方案列表
        planList: [],
        // 当前客户端的支付方式列表（后端根据platform判断）
        methods: [],
        // 当前选中的套餐ID
        selectedPlanId: 0,
        // 自定义金额
        inputValue: '',
        // 当前选中的支付方式
        curPaymentItem: null,
        // 支付确认弹窗
        showConfirmModal: false,
        // #ifdef H5
        // 当前第三方支付信息 (临时数据, 仅用于H5端)
        tempUnifyData: { outTradeNo: '', method: '' },
        // #endif
      }
    },

    /**
     * 生命周期函数--监听页面显示
     */
    onShow() {
      // 获取页面数据
      this.getPageData()
    },

    methods: {

      // 选择充值套餐
      onSelectPlan(planId) {
        this.selectedPlanId = planId
        this.inputValue = ''
      },

      // 金额输入框
      onChangeMoney({ detail }) {
        this.inputValue = detail.value
        this.selectedPlanId = 0
      },

      // 选择支付方式
      handleSelectPayType(index) {
        this.curPaymentItem = this.methods[index]
      },

      // 获取页面数据
      getPageData() {
        const app = this
        app.isLoading = true
        return new Promise((resolve, reject) => {
          RechargeApi.center({ client: app.platform })
            .then(result => {
              app.setting = result.data.setting
              app.personal = result.data.personal
              app.planList = result.data.planList
              app.methods = result.data.paymentMethods
              app.isLoading = false
              // 默认选中的支付方式
              app.setDefaultPayType()
              // #ifdef H5
              // 判断当前页面来源于浏览器返回
              this.performance()
              // #endif
            })
        })
      },

      // 默认选中的支付方式
      setDefaultPayType() {
        if (!this.curPaymentItem) {
          this.handleSelectPayType(0)
        }
      },

      // 判断当前页面来源于浏览器返回并提示手动查单
      // #ifdef H5
      performance() {
        const app = this
        const performanceData = getTempUnifyData('recharge')
        if (performanceData) {
          app.tempUnifyData = performanceData
          app.showConfirmModal = true
        }
      },
      // #endif

      // 立即充值
      onSubmit(e) {
        const app = this
        // 判断是否选择了支付方式
        if (!app.curPaymentItem) {
          app.$toast('您还没有选择支付方式')
          return
        }
        // 按钮禁用
        if (app.disabled) return
        app.disabled = true
        // 提交到后端
        RechargeApi.submit({
            planId: app.selectedPlanId,
            customMoney: app.inputValue ? app.inputValue : '',
            method: app.curPaymentItem.method,
            client: app.platform,
            extra: app.getExtraAsUnify(app.curPaymentItem.method)
          })
          .then(result => app.onSubmitCallback(result))
          .finally(err => {
            setTimeout(() => app.disabled = false, 10)
          })
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
          Alipay.payment({ orderKey: 'recharge', ...paymentData })
            .then(res => app.onPaySuccess(res))
            .catch(err => app.onPayFail(err))
        }
        // 发起微信支付
        if (method === PayMethodEnum.WECHAT.value) {
          console.log('paymentData', paymentData)
          Wechat.payment({ orderKey: 'recharge', ...paymentData })
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
        RechargeApi.tradeQuery({ outTradeNo, method, client: app.platform })
          .then(result => result.data.isPay ? app.onShowSuccess(result) : app.onPayFail(result))
          .finally(() => app.showConfirmModal = false)
      },

      // 支付成功后的跳转
      onSuccessNav() {
        const pages = getCurrentPages()
        const lastPage = pages.length < 2 ? null : pages[pages.length - 2]
        const backRoutes = ['pages/wallet/index']
        if (lastPage && inArray(lastPage.route, backRoutes)) {
          setTimeout(() => uni.navigateBack(), 1000)
        } else {
          setTimeout(() => {
            this.$navTo('pages/wallet/index', {}, 'redirectTo')
          }, 1200)
        }
      },

    }
  }
</script>

<style lang="scss" scoped>
  page,
  .container {
    background: #fff;
  }

  .container {
    padding-bottom: 70rpx;
  }

  .m-top60 {
    margin-top: 60rpx;
  }

  // 账户面板
  .account-panel {
    width: 650rpx;
    height: 180rpx;
    margin: 50rpx auto;
    padding: 0 60rpx;
    box-sizing: border-box;
    border-radius: 12rpx;
    color: #fff;
    background: linear-gradient(-125deg, #a46bff, #786cff);
    box-shadow: 0 5px 22px 0 rgba(0, 0, 0, 0.26);
  }

  .panel-lable {
    font-size: 32rpx;
  }

  .recharge-label {
    color: rgb(51, 51, 51);
    font-size: 30rpx;
    margin-bottom: 25rpx;
  }

  .panel-balance {
    text-align: right;
    font-size: 46rpx;
  }

  .recharge-panel {
    margin-top: 60rpx;
    padding: 0 60rpx;
  }

  // 充值套餐
  .recharge-plan {
    margin-bottom: -20rpx;

    .recharge-plan_item {
      width: 192rpx;
      padding: 15rpx 0;
      float: left;
      text-align: center;
      color: #888;
      border: 1rpx solid rgb(228, 228, 228);
      border-radius: 10rpx;
      margin: 0 20rpx 20rpx 0;

      &:nth-child(3n + 0) {
        margin-right: 0;
      }

      &.active {
        color: #786cff;
        border: 1rpx solid #786cff;

        .plan_money {
          color: #786cff;
        }
      }
    }

  }

  .plan_money {
    font-size: 32rpx;
    color: rgb(82, 82, 82);
  }

  .plan_gift {
    font-size: 25rpx;
  }

  .recharge-input {
    margin-top: 40rpx;

    .input {
      display: block;
      border: 1rpx solid rgb(228, 228, 228);
      border-radius: 10rpx;
      padding: 20rpx 26rpx;
      font-size: 28rpx;
    }
  }

  // 立即充值
  .recharge-submit {
    margin-top: 70rpx;
  }

  .btn-submit {
    .button {
      font-size: 30rpx;
      background: #786cff;
      border: none;
      color: white;
      border-radius: 50rpx;
      padding: 0 120rpx;
      line-height: 3;
    }

    .button[disabled] {
      background: #a098ff;
      border-color: #a098ff;
      color: white;
    }
  }

  // 充值说明
  .describe-panel {
    margin-top: 50rpx;
    padding: 0 60rpx;

    .content {
      font-size: 26rpx;
      line-height: 1.6;
      color: #888;
    }
  }

  // 支付方式
  .payment-method {

    .pay-item {
      padding: 14rpx 0;
      font-size: 26rpx;

      .item-left_icon {
        margin-right: 20rpx;
        font-size: 44rpx;

        &.wechat {
          color: #00c800;
        }

        &.alipay {
          color: #009fe8;
        }

      }

      .item-left_text {
        font-size: 30rpx;
      }

      .item-right {
        font-size: 30rpx;
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
</style>