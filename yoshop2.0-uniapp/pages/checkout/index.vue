<template>
  <view class="container p-bottom" :style="appThemeStyle">
    <view v-if="order.goodsList && order.goodsList.length">
      <view v-if="isServiceCheckout" class="service-card b-f m-top20">
        <view class="service-title">服务订单信息</view>
        <view class="form-item form-item--block">
          <text class="label">端游 / 手游</text>
          <view class="option-group">
            <view class="option-chip" :class="{ active: gamePlatform === 'pc' }" @click="gamePlatform = 'pc'">端游</view>
            <view class="option-chip" :class="{ active: gamePlatform === 'mobile' }" @click="gamePlatform = 'mobile'">手游</view>
          </view>
        </view>
        <view class="form-item">
          <text class="label">游戏 ID</text>
          <input class="input" v-model="gameAccountId" placeholder="请填写游戏账号 ID" />
        </view>
        <view class="form-item">
          <text class="label">联系方式</text>
          <input class="input" v-model="contactMobile" type="number" maxlength="11" placeholder="请填写应急联系方式" />
        </view>
        <view class="form-item form-item--block">
          <text class="label">成年人下单</text>
          <view class="confirm-box" :class="{ active: adultConfirm }" @click="adultConfirm = !adultConfirm">
            <text class="confirm-icon">{{ adultConfirm ? '☑' : '☐' }}</text>
            <text>确认成年人下单</text>
          </view>
        </view>
        <view class="form-item form-item--textarea">
          <text class="label">备注</text>
          <textarea class="textarea" v-model="remark" maxlength="100" placeholder="如有其他要求，请再次补充。" />
        </view>
      </view>

      <view class="checkout_list" v-for="(item, index) in order.goodsList" :key="index">
        <view class="flow-shopList dis-flex" @click="onTargetGoods(item.goods_id)">
          <view class="flow-list-left"><image mode="scaleToFill" :src="item.goods_image"></image></view>
          <view class="flow-list-right flex-box">
            <text class="goods-name twoline-hide">{{ item.goods_name }}</text>
            <view class="goods-props clearfix">
              <view class="goods-props-item" v-for="(props, idx) in item.skuInfo.goods_props" :key="idx"><text class="group-name">{{ props.group.name }}: </text><text>{{ props.value.name }}；</text></view>
            </view>
            <view class="flow-list-cont dis-flex flex-x-between flex-y-center"><text class="small">×{{ item.total_num }}</text><text class="flow-cont" :class="[item.is_user_grade ? 'price-delete' : '']">￥{{ item.goods_price }}</text></view>
            <view v-if="item.is_user_grade" class="grade-price"><text>会员折扣价：￥{{ item.grade_goods_price }}</text></view>
          </view>
        </view>
      </view>

      <view class="flow-num-box b-f"><text>共{{ order.orderTotalNum }}件商品</text></view>

      <view class="flow-all-money b-f m-top20">
        <view class="flow-all-list dis-flex"><text class="flex-five">商品金额：</text><view class="flex-five t-r"><text class="col-m">￥{{ order.orderTotalPrice }}</text></view></view>
        <view v-if="showCouponEntry && $checkModule('market-coupon')" class="flow-all-list dis-flex"><text class="flex-five">优惠券：</text><view class="flex-five t-r"><view v-if="order.couponList.length > 0" @click="handleShowPopup()"><text class="col-m" v-if="order.couponId > 0">-￥{{ order.couponMoney }}</text><text class="col-m" v-else>有{{ order.couponList.length }}张优惠券</text><text class="right-arrow iconfont icon-arrow-right"></text></view><text v-else>无优惠券可用</text></view></view>
      </view>

      <view class="flow-fixed-footer b-f m-top10">
        <view class="dis-flex chackout-box">
          <view class="chackout-left pl-12">应付：<text class="col-m">￥{{ order.orderPayPrice }}</text></view>
          <view class="chackout-right" @click="onSubmitOrder()"><view class="flow-btn f-32" :class="{ disabled }">提交订单</view></view>
        </view>
      </view>

      <u-popup v-if="showCouponEntry" v-model="showPopup" mode="bottom">
        <view class="popup__coupon">
          <view class="coupon__title f-30">选择优惠券</view>
          <scroll-view :scroll-y="true" style="height: 650rpx;">
            <view class="coupon-list">
              <view class="coupon-item" v-for="(item, index) in order.couponList" :key="index">
                <view class="item-wrapper" :class="[ !item.state.value ? 'disable' : '' ]" @click="handleSelectCoupon(index)">
                  <view class="coupon-content">
                    <view class="coupon-name">{{ item.name }}</view>
                    <view class="coupon-middle"><view class="coupon-expire"><text v-if="item.expire_type == CouponTypeEnum.FULL_DISCOUNT.value">领取后{{ item.expire_day }}天内有效</text><text v-if="item.expire_type == CouponTypeEnum.DISCOUNT.value">{{ item.start_time }}~{{ item.end_time }}</text></view></view>
                  </view>
                  <view class="coupon-right"><view class="my-radio" :class="{ checked: selectCouponId == item.user_coupon_id }"><u-icon name="checkbox-mark" :size="20" /></view></view>
                </view>
              </view>
            </view>
          </scroll-view>
          <view class="coupon__do_not"><view class="control" @click="handleNotUseCoupon()"><text class="f-26">不使用优惠券</text></view></view>
        </view>
      </u-popup>
    </view>
    <u-toast ref="uToast" />
  </view>
</template>

<script>
  import * as Verify from '@/utils/verify'
  import * as CheckoutApi from '@/api/checkout'
  import { CouponTypeEnum } from '@/common/enum/coupon'
  import { isH5, isMpWeixin, isWeixinOfficial } from '@/core/platform'

  const getCheckoutApi = () => CheckoutApi
  const getMode = (options) => options.mode || 'buyNow'
  const getModeParam = (options) => getMode(options) === 'cart'
    ? { cartIds: options.cartIds || '' }
    : { goodsId: options.goodsId, goodsNum: options.goodsNum, goodsSkuId: options.goodsSkuId }

  export default {
    computed: {
      showCouponEntry () {
        return !(isH5 || isMpWeixin || isWeixinOfficial)
      },
      isServiceCheckout () {
        const order = this.order || {}
        if (order && Object.prototype.hasOwnProperty.call(order, 'isServicePackage')) {
          return !!order.isServicePackage
        }
        return (this.options.scene || '') === 'service'
      }
    },
    data () {
      return {
        CouponTypeEnum,
        options: {},
        selectCouponId: 0,
        remark: '',
        gamePlatform: '',
        gameAccountId: '',
        contactMobile: '',
        adultConfirm: false,
        disabled: false,
        showPopup: false,
        order: {},
        personal: {},
        setting: {}
      }
    },
    onLoad (options) {
      this.options = options
      if (this.isUnsupportedScene(options)) {
        uni.showToast({ title: '该结算入口已停用', icon: 'none' })
        setTimeout(() => uni.navigateBack({ delta: 1 }), 1200)
      }
    },
    onShow () {
      this.getOrderData()
    },
    methods: {
      getOrderData () {
        const params = this.getRequestParam()
        getCheckoutApi().order(getMode(this.options), params).then(result => this.initData(result.data)).catch(err => err)
      },
      initData ({ order, setting, personal }) {
        this.order = order
        this.personal = personal
        this.setting = setting
        if (order.hasError) this.showToast(order.errorMsg, 3000)
        this.selectCouponId = order.couponId
      },
      getRequestParam () {
        const params = {
          scene: this.options.scene || '',
          couponId: this.selectCouponId || 0,
          remark: this.remark || '',
          ...getModeParam(this.options)
        }
        if (this.isServiceCheckout) {
          params.gamePlatform = this.gamePlatform || ''
          params.gameAccountId = this.gameAccountId || ''
          params.contactMobile = this.contactMobile || ''
          params.adultConfirm = this.adultConfirm ? 1 : 0
        }
        return params
      },
      isUnsupportedScene () {
        return false
      },
      handleShowPopup () {
        this.showPopup = true
      },
      handleSelectCoupon (index) {
        const couponItem = this.order.couponList[index]
        if (!couponItem.is_apply) return this.showToast(couponItem.not_apply_info)
        this.selectCouponId = this.selectCouponId === couponItem.user_coupon_id ? 0 : couponItem.user_coupon_id
        this.getOrderData()
        this.showPopup = false
      },
      handleNotUseCoupon () {
        this.selectCouponId = 0
        this.getOrderData()
        this.showPopup = false
      },
      onTargetGoods (goodsId) {
        this.$navTo('pages/goods/detail', { goodsId })
      },
      onSubmitOrder () {
        if (this.disabled) return false
        if (!this.onVerifyFrom()) return false
        this.disabled = true
        getCheckoutApi().submit(getMode(this.options), this.getFormData()).then(result => {
          const orderId = result.data.orderId
          if (result.data.isPaySuccess) {
            this.showToast(result.message, 1500)
            setTimeout(() => this.$navTo('pages/order/index', {}, 'redirectTo'), 1500)
          } else {
            setTimeout(() => this.$navTo('pages/checkout/cashier/index', { orderId }, 'redirectTo'), 100)
          }
        }).catch(res => this.showToast(res.errMsg, 3000)).finally(() => setTimeout(() => { this.disabled = false }, 1600))
      },
      getFormData () {
        const data = {
          scene: this.options.scene || '',
          couponId: this.selectCouponId || 0,
          remark: this.remark || '',
          ...getModeParam(this.options)
        }
        if (this.isServiceCheckout) {
          data.gamePlatform = this.gamePlatform || ''
          data.gameAccountId = this.gameAccountId || ''
          data.contactMobile = this.contactMobile || ''
          data.adultConfirm = this.adultConfirm ? 1 : 0
        }
        return data
      },
      onVerifyFrom () {
        if (!this.isServiceCheckout) return true
        if (!this.gamePlatform) return this.showToast('请选择端游或手游'), false
        if (!this.gameAccountId) return this.showToast('请输入游戏ID'), false
        if (!Verify.isMobile(this.contactMobile)) return this.showToast('请输入正确的联系方式'), false
        if (!this.adultConfirm) return this.showToast('请确认成年人下单'), false
        return true
      },
      showToast (title, duration = 2000) {
        this.$refs.uToast.show({ title, duration })
      }
    }
  }
</script>

<style lang="scss" scoped>
  @import "./style.scss";
  .service-card { padding: 24rpx 30rpx; background: #fff; }
  .service-title { font-size: 30rpx; font-weight: 600; margin-bottom: 20rpx; }
  .form-item { display: flex; align-items: center; padding: 18rpx 0; border-bottom: 1rpx solid #f3f3f3; .label { width: 170rpx; color: #666; font-size: 28rpx; } .input, .textarea { flex: 1; font-size: 28rpx; } }
  .form-item--textarea { align-items: flex-start; .textarea { min-height: 120rpx; padding-top: 8rpx; } }
  .form-item--block { align-items: flex-start; .label { padding-top: 8rpx; } }
  .option-group { display: flex; flex-wrap: wrap; gap: 18rpx; }
  .option-chip { min-width: 140rpx; padding: 14rpx 28rpx; border-radius: 999rpx; border: 1rpx solid #d8d8d8; color: #666; text-align: center; }
  .option-chip.active { color: #fff; background: linear-gradient(135deg, #f4c46c, #d89b3d); border-color: transparent; }
  .confirm-box { display: flex; align-items: center; color: #666; font-size: 28rpx; }
  .confirm-box.active { color: #333; font-weight: 500; }
  .confirm-icon { margin-right: 14rpx; font-size: 32rpx; }
</style>
