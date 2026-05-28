<template>
  <view class="container p-bottom" :style="appThemeStyle">
    <view v-if="order.goodsList && order.goodsList.length">
      <!-- 实物订单：选择配送方式 -->
      <block v-if="order.orderType == OrderTypeEnum.PHYSICAL.value">
        <!-- 配送方式选项卡 -->
        <view v-if="isShowTab" class="swiper-tab dis-flex flex-y-center flex-x-around">
          <view class="swiper-tab-item" :class="{ on: curDelivery == DeliveryTypeEnum.EXPRESS.value }"
            @click="handleSwichDelivery(DeliveryTypeEnum.EXPRESS.value)">
            <text>快递配送</text>
          </view>
        </view>

        <!-- 快递配送：配送地址 -->
        <view v-if="curDelivery == DeliveryTypeEnum.EXPRESS.value" @click="onSelectAddress" class="flow-delivery">
          <view class="flow-delivery__detail dis-flex flex-y-center">
            <view class="detail-location dis-flex">
              <text class="iconfont icon-dingwei"></text>
            </view>
            <view class="detail-content flex-box">
              <block v-if="order.address">
                <view class="detail-content__title dis-flex">
                  <text class="f-30">{{ order.address.name }}</text>
                  <text class="detail-content__title-phone f-28">{{ order.address.phone }}</text>
                </view>
                <view class="address detail-content__describe">
                  <text class="region" v-for="(region, idx) in order.address.region" :key="idx">{{ region }}</text>
                  <text class="detail">{{ order.address.detail }}</text>
                </view>
              </block>
              <block v-else>
                <view class="detail-content__describe dis-flex">
                  <text class="col-6">请选择配送地址</text>
                </view>
              </block>
            </view>
            <view class="detail-arrow dis-flex">
              <text class="iconfont icon-arrow-right"></text>
            </view>
          </view>
        </view>

      </block>

      <!-- 商品列表 -->
      <view class="checkout_list" v-for="(item, index) in order.goodsList" :key="index">
        <view class="flow-shopList dis-flex" data-index="index" @click="onTargetGoods(item.goods_id)">
          <!-- 商品图片 -->
          <view class="flow-list-left">
            <image mode="scaleToFill" :src="item.goods_image"></image>
          </view>
          <view class="flow-list-right flex-box">
            <!-- 商品名称 -->
            <text class="goods-name twoline-hide">{{ item.goods_name }}</text>
            <!-- 商品规格 -->
            <view class="goods-props clearfix">
              <view class="goods-props-item" v-for="(props, idx) in item.skuInfo.goods_props" :key="idx">
                <text class="group-name">{{ props.group.name }}: </text>
                <text>{{ props.value.name }}；</text>
              </view>
            </view>
            <!-- 商品数量和单价 -->
            <view class="flow-list-cont dis-flex flex-x-between flex-y-center">
              <text class="small">×{{ item.total_num }}</text>
              <text class="flow-cont" :class="[item.is_user_grade ? 'price-delete' : '']">￥{{ item.goods_price }}</text>
            </view>
            <!-- 会员折扣价 -->
            <view v-if="item.is_user_grade" class="grade-price">
              <text>会员折扣价：￥{{ item.grade_goods_price }}</text>
            </view>
          </view>
        </view>
      </view>
      <view class="flow-num-box b-f">
        <!-- <text>共{{ order.orderTotalNum }}件商品，合计：</text>
        <text class="flow-money col-m">￥{{ order.orderTotalPrice }}</text> -->
        <text>共{{ order.orderTotalNum }}件商品</text>
      </view>

      <!-- 商品金额 -->
      <view class="flow-all-money b-f m-top20">
        <view class="flow-all-list dis-flex">
          <text class="flex-five">订单总金额：</text>
          <view class="flex-five t-r">
            <text class="col-m">￥{{ order.orderTotalPrice }}</text>
          </view>
        </view>
        <!-- 优惠券 -->
        <view v-if="$checkModule('market-coupon')" class="flow-all-list dis-flex">
          <text class="flex-five">优惠券：</text>
          <view class="flex-five t-r">
            <view v-if="order.couponList.length > 0" @click="handleShowPopup()">
              <text class="col-m" v-if="order.couponId > 0">-￥{{ order.couponMoney }}</text>
              <text class="col-m" v-else>有{{ order.couponList.length }}张优惠券</text>
              <text class="right-arrow iconfont icon-arrow-right"></text>
            </view>
            <text v-else class="">无优惠券可用</text>
          </view>
        </view>
        <!-- 积分抵扣 -->
        <view v-if="$checkModule('market-points') && order.isAllowPoints" class="points flow-all-list dis-flex flex-y-center">
          <view class="block-left flex-five" @click="handleShowPoints()">
            <text class="title">可用{{ setting.points_name }}抵扣：</text>
            <text class="iconfont icon-help"></text>
          </view>
          <view class="flex-five dis-flex flex-x-end flex-y-center">
            <text class="points-money col-m">-￥{{ order.pointsMoney }}</text>
            <u-switch v-model="isUsePoints" size="42" :active-color="appTheme.mainBg" @change="getOrderData()"></u-switch>
          </view>
        </view>
        <!-- 配送费用 -->
        <view v-if="curDelivery == DeliveryTypeEnum.EXPRESS.value" class="dis-flex flow-all-list">
          <text class="flex-five">配送费用：</text>
          <view class="flex-five t-r">
            <view v-if="order.address">
              <text class="col-m" v-if="order.isIntraRegion">+￥{{ order.expressPrice }}</text>
              <text v-else>不在配送范围</text>
            </view>
            <view v-else>
              <text class="col-7">请先选择配送地址</text>
            </view>
          </view>
        </view>
      </view>

      <!-- 买家留言 -->
      <view class="flow-all-money b-f m-top20">
        <view class="ipt-wrapper flow-all-list">
          <input class="input" v-model="remark" placeholder="选填：买家留言（50字以内）" />
        </view>
      </view>

      <!-- 提交订单 -->
      <view class="flow-fixed-footer b-f m-top10">
        <view class="dis-flex chackout-box">
          <view class="chackout-left pl-12">实付款：
            <text class="col-m">￥{{ order.orderPayPrice }}</text>
          </view>
          <view class="chackout-right" @click="onSubmitOrder()">
            <view class="flow-btn f-32" :class="{ disabled }">提交订单</view>
          </view>
        </view>
      </view>

      <!-- 积分说明弹窗 -->
      <u-modal v-model="showPoints" :title="`${setting.points_name}说明`">
        <scroll-view class="points-content" :scroll-y="true">
          <text>{{ setting.points_describe }}</text>
        </scroll-view>
      </u-modal>

      <!-- 优惠券弹出框 -->
      <u-popup v-model="showPopup" mode="bottom">
        <view class="popup__coupon">
          <view class="coupon__title f-30">选择优惠券</view>
          <!-- 优惠券列表 -->
          <scroll-view :scroll-y="true" style="height: 650rpx;">
            <view class="coupon-list">
              <view class="coupon-item" v-for="(item, index) in order.couponList" :key="index">
                <view class="item-wrapper" :class="[ !item.state.value ? 'disable' : '' ]" @click="handleSelectCoupon(index)">
                  <!-- 优惠券类型 (标签) -->
                  <view class="coupon-tag">
                    <text>{{ CouponTypeEnum[item.coupon_type].name }}</text>
                  </view>
                  <view class="coupon-left">
                    <!-- 优惠额度/折扣 -->
                    <view class="coupon-reduce">
                      <block v-if="item.coupon_type == CouponTypeEnum.FULL_DISCOUNT.value">
                        <view class="coupon-reduce-unit"><text>￥</text></view>
                        <view class="coupon-reduce-amount">
                          <text class="value">{{ item.reduce_price }}</text>
                        </view>
                      </block>
                      <block v-if="item.coupon_type == CouponTypeEnum.DISCOUNT.value">
                        <view class="coupon-reduce-amount">
                          <text class="value">{{ item.discount }}折</text>
                        </view>
                      </block>
                    </view>
                    <!-- 最低消费金额 -->
                    <text class="coupon-hint">满{{ item.min_price }}元可用</text>
                  </view>
                  <view class="coupon-content">
                    <view class="coupon-name">{{ item.name }}</view>
                    <view class="coupon-middle">
                      <view class="coupon-expire">
                        <text v-if="item.expire_type == CouponTypeEnum.FULL_DISCOUNT.value">领取后{{ item.expire_day }}天内有效</text>
                        <text v-if="item.expire_type == CouponTypeEnum.DISCOUNT.value">
                          <block v-if="item.start_time === item.end_time">{{ item.start_time }} 当天有效</block>
                          <block v-else>{{ item.start_time }}~{{ item.end_time }}</block>
                        </text>
                      </view>
                    </view>
                    <view v-if="item.describe" class="coupon-expand" @click.stop="handleDescribe(index)">
                      <text>使用说明</text>
                      <text class="coupon-expand-arrow iconfont icon-arrow-down" :class="[item.expand ? 'expand' : '']" />
                    </view>
                  </view>
                  <view class="coupon-right">
                    <view class="my-radio" :class="{ checked: selectCouponId == item.user_coupon_id }">
                      <u-icon name="checkbox-mark" :size="20" />
                    </view>
                  </view>
                </view>
                <!-- 优惠券描述 -->
                <view :class="[item.expand ? 'expand' : '']" class="coupon-expand-rules">
                  <view class="coupon-expand-rules-content">
                    <view class="pre">{{ item.describe }}</view>
                  </view>
                </view>
              </view>
            </view>
          </scroll-view>
          <!-- 不使用优惠券 -->
          <view class="coupon__do_not">
            <view class="control" @click="handleNotUseCoupon()">
              <text class="f-26">不使用优惠券</text>
            </view>
          </view>
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
  import { OrderTypeEnum, DeliveryTypeEnum } from '@/common/enum/order'

  const CouponColors = ['red', 'blue', 'violet', 'yellow']

  // 根据指定mode获取对应的api类
  const getCheckoutApi = (mode) => {
    const apiEnum = {
      buyNow: CheckoutApi,
      cart: CheckoutApi
    }
    return apiEnum[mode]
  }

  // 根据指定mode获取param
  const getModeParam = (mode, options) => {
    const param = {}
    // 结算模式: 立即购买
    if (mode === 'buyNow') {
      param.goodsId = options.goodsId
      param.goodsNum = options.goodsNum
      param.goodsSkuId = options.goodsSkuId
    }
    // 结算模式: 购物车
    if (mode === 'cart') {
      param.cartIds = options.cartIds
    }
    return param
  }

  export default {
    data() {
      return {
        // 枚举类
        OrderTypeEnum,
        DeliveryTypeEnum,
        CouponTypeEnum,
        // 当前页面参数
        options: {},
        // 配送方式
        isShowTab: false,
        DeliveryTypeEnum,
        curDelivery: null,
        // 优惠券颜色组
        CouponColors,
        // 选择的优惠券
        selectCouponId: 0,
        // 是否使用积分抵扣
        isUsePoints: false,
        // 买家留言
        remark: '',
        // 禁用submit按钮
        disabled: false,
        // 是否显示积分说明
        showPoints: false,
        // 是否显示优惠券弹窗
        showPopup: false,
        // 订单信息 (从后端api中获取)
        order: {},
        // 个人信息
        personal: {},
        // 商城设置
        setting: {}
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad(options) {
      this.options = options
    },

    /**
     * 生命周期函数--监听页面的卸载
     */
    onUnload() {

    },

    /**
     * 生命周期函数--监听页面显示
     */
    onShow() {
      // 获取当前订单信息
      this.getOrderData()
    },

    methods: {

      // 获取订单数据
      getOrderData() {
        const app = this
        const { options: { mode } } = app
        // 请求的参数
        const params = app.getRequestParam()
        // 请求api
        getCheckoutApi(mode)
          .order(mode, params)
          .then(result => app.initData(result.data))
          .catch(err => err)
      },

      // 初始化数据
      initData({ order, setting, personal }) {
        const app = this
        app.order = order
        app.personal = personal
        app.setting = setting
        // 显示错误信息
        if (order.hasError) {
          app.showToast(order.errorMsg, 3000)
        }
        // 当前选择的配送方式
        app.curDelivery = order.delivery
        // 如果只有一种配送方式则不显示选项卡
        app.isShowTab = setting.deliveryType.length > 1
        // 是否使用积分抵扣
        app.isUsePoints = order.isUsePoints
        app.selectCouponId = order.couponId
      },

      // 获取api请求的参数
      getRequestParam() {
        const app = this
        const { options } = app
        // 结算模式的固定参数
        const modeParam = getModeParam(options.mode, options)
        // 订单结算参数(用户选择)
        const orderParam = {
          delivery: app.curDelivery || 0,
          couponId: app.selectCouponId || 0,
          isUsePoints: app.isUsePoints ? 1 : 0,
        }
        return { ...orderParam, ...modeParam }
      },

      // 切换配送方式
      handleSwichDelivery(key) {
        this.curDelivery = key
        this.getOrderData()
      },

      // 显示积分抵扣说明
      handleShowPoints() {
        this.showPoints = true
      },

      // 显示优惠券弹窗
      handleShowPopup() {
        this.showPopup = true
      },

      // 展开优惠券描述
      handleDescribe(index) {
        const { couponList } = this.order
        couponList[index].expand = !couponList[index].expand
      },

      // 选择优惠券
      handleSelectCoupon(index) {
        const app = this
        const { couponList } = app.order
        // 当前选择的优惠券
        const couponItem = couponList[index]
        // 判断是否在适用范围
        if (!couponItem.is_apply) {
          app.showToast(couponItem.not_apply_info)
          return
        }
        // 记录选中的优惠券ID
        app.selectCouponId = app.selectCouponId == couponItem.user_coupon_id ? 0 : couponItem.user_coupon_id
        // 重新获取订单信息
        app.getOrderData()
        // 隐藏优惠券弹层
        app.showPopup = false
      },

      // 不使用优惠券
      handleNotUseCoupon() {
        this.selectCouponId = 0
        // 重新获取订单信息
        this.getOrderData()
        // 隐藏优惠券弹层
        this.showPopup = false
      },

      // 快递配送：选择收货地址
      onSelectAddress() {
        this.$navTo('pages/address/index', { from: 'checkout' })
      },

      // 跳转到商品详情页
      onTargetGoods(goodsId) {
        this.$navTo('pages/goods/detail', { goodsId })
      },

      // 订单提交
      onSubmitOrder() {
        const app = this
        if (app.disabled) {
          return false
        }
        // 表单验证
        if (!app.onVerifyFrom()) {
          return false
        }
        // 按钮禁用
        app.disabled = true
        // 请求api
        getCheckoutApi(app.options.mode)
          .submit(app.options.mode, app.getFormData())
          .then(result => {
            // 订单创建成功
            const orderId = result.data.orderId
            // 判断订单是否已支付: 已支付跳转订单列表  未支付跳转支付页
            if (result.data.isPaySuccess) {
              app.showToast(result.message, 1500)
              setTimeout(() => app.$navTo('pages/order/index', {}, 'redirectTo'), 1500)
            } else {
              // 订单未支付: 跳转到订单支付页
              setTimeout(() => app.$navTo('pages/checkout/cashier/index', { orderId }, 'redirectTo'), 100)
            }
          })
          .catch(res => app.showToast(res.errMsg, 3000))
          .finally(() => setTimeout(() => app.disabled = false, 1600))
      },

      // 表单提交的数据
      getFormData() {
        const app = this
        const { options } = app
        // 表单数据
        const form = {
          delivery: app.curDelivery,
          couponId: app.selectCouponId || 0,
          isUsePoints: app.isUsePoints ? 1 : 0,
          remark: app.remark || '',
        }
        // 获取不同模式的参数
        const modeParam = getModeParam(options.mode, options)
        return { ...form, ...modeParam }
      },

      // 表单验证
      onVerifyFrom() {
        const app = this
        if (app.hasError) {
          app.showToast(app.errorMsg, 3000)
          return false
        }
        return true
      },

      // 显示toast信息
      showToast(title, duration = 2000) {
        this.$refs.uToast.show({ title, duration })
      }

    }
  }
</script>

<style lang="scss" scoped>
  @import "./style.scss";
</style>