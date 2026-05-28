<template>
  <view v-if="!isLoading" class="container" :style="appThemeStyle">

    <view class="header">
      <!-- 订单状态 -->
      <view class="order-status">
        <view class="status-icon">
          <!-- 进行中的订单 -->
          <block v-if="order.order_status == OrderStatusEnum.NORMAL.value">
            <!-- 待支付 -->
            <block v-if="order.pay_status == PayStatusEnum.PENDING.value">
              <image class="image" src="/static/order/status/wait_pay.png" mode="aspectFit"></image>
            </block>
            <!-- 待发货 -->
            <block v-else-if="order.delivery_status == DeliveryStatusEnum.NOT_DELIVERED.value">
              <image class="image" src="/static/order/status/wait_deliver.png" mode="aspectFit"></image>
            </block>
            <!-- 待收货 -->
            <block v-else-if="order.receipt_status == ReceiptStatusEnum.NOT_RECEIVED.value">
              <image class="image" src="/static/order/status/wait_receipt.png" mode="aspectFit"></image>
            </block>
          </block>
          <!-- 已完成 -->
          <block v-if="order.order_status == OrderStatusEnum.COMPLETED.value">
            <image class="image" src="/static/order/status/received.png" mode="aspectFit"></image>
          </block>
          <!-- 已取消/待取消 -->
          <block v-if="order.order_status == OrderStatusEnum.CANCELLED.value || order.order_status == OrderStatusEnum.APPLY_CANCEL.value">
            <image class="image" src="/static/order/status/close.png" mode="aspectFit"></image>
          </block>
        </view>
        <view class="status-text">
          <text>{{ order.state_text }}</text>
        </view>
      </view>
      <!-- 下一步操作 -->
      <view class="next-action" v-if="order.order_status == OrderStatusEnum.NORMAL.value">
        <view v-if="order.pay_status == PayStatusEnum.PENDING.value" class="action-btn" @click="onPay(order.order_id)">
          去支付</view>
        <view
          v-if="order.delivery_status == DeliveryStatusEnum.DELIVERED.value && order.receipt_status == ReceiptStatusEnum.NOT_RECEIVED.value"
          class="action-btn" @click="onReceipt(order.order_id)">确认收货</view>
      </view>
    </view>

    <view class="card-area">
      <!-- 实物订单 -->
      <block v-if="order.order_type == OrderTypeEnum.PHYSICAL.value">
        <!-- 快递配送：配送地址 -->
        <view v-if="order.delivery_type == DeliveryTypeEnum.EXPRESS.value" class="delivery-address i-card">
          <view class="link-man">
            <text class="name">{{ order.address.name }}</text>
            <text class="phone">{{ order.address.phone }}</text>
          </view>
          <view class="address">
            <text class="region" v-for="(region, idx) in order.address.region" :key="idx">{{ region }}</text>
            <text class="detail">{{ order.address.detail }}</text>
          </view>
        </view>
      </block>

      <!-- 物流信息 -->
      <view v-if="order.delivery_type == DeliveryTypeEnum.EXPRESS.value
       && order.delivery_status == DeliveryStatusEnum.DELIVERED.value
        && order.delivery
         && order.delivery.length" class="express i-card" @click="handleTargetExpress()">
        <view v-if="order.delivery.length > 1" class="main">
          <view class="info-item">
            <view class="item-content">
              <text>订单已拆分多个包裹发货</text>
            </view>
          </view>
          <view class="info-item">
            <view class="item-content">
              <text>已发货{{ order.delivery.length }}个包裹</text>
            </view>
          </view>
        </view>
        <view v-else class="main">
          <view class="info-item">
            <view class="item-lable">物流公司</view>
            <view class="item-content">
              <text
                v-if="order.delivery[0].delivery_method != DeliveryMethodEnum.UNWANTED.value">{{ order.delivery[0].express ? order.delivery[0].express.express_name : '--' }}</text>
              <text v-else>无需物流</text>
            </view>
          </view>
          <view class="info-item">
            <view class="item-lable">物流单号</view>
            <view class="item-content">
              <text>{{ order.delivery[0].express_no ? order.delivery[0].express_no : '--' }}</text>
              <view v-if="order.delivery[0].express_no" class="act-copy" @click.stop="handleCopy(order.delivery[0].express_no)">
                <text>复制</text>
              </view>
            </view>
          </view>
        </view>
        <view class="right-arrow">
          <text class="iconfont icon-arrow-right"></text>
        </view>
      </view>

      <!-- 商品列表 -->
      <view class="goods-list i-card">
        <view class="goods-item" v-for="(goods, idx) in order.goods" :key="idx">
          <view class="goods-main" @click="handleTargetGoods(goods.goods_id)">
            <!-- 商品图片 -->
            <view class="goods-image">
              <image class="image" :src="goods.goods_image" mode="scaleToFill"></image>
            </view>
            <!-- 商品信息 -->
            <view class="goods-content">
              <view class="goods-title"><text class="twoline-hide">{{ goods.goods_name }}</text></view>
              <view class="goods-props clearfix">
                <view class="goods-props-item" v-for="(props, idx) in goods.goods_props" :key="idx">
                  <text>{{ props.value.name }}</text>
                </view>
              </view>
            </view>
            <!-- 交易信息 -->
            <view class="goods-trade">
              <view class="goods-price">
                <text class="unit">￥</text>
                <text class="value">{{ goods.is_user_grade ? goods.grade_goods_price : goods.goods_price }}</text>
              </view>
              <view class="goods-num">
                <text>×{{ goods.total_num }}</text>
              </view>
            </view>
          </view>
          <!-- 商品售后 -->
          <view class="goods-refund">
            <text v-if="goods.refund" class="stata-text">已申请售后</text>
            <view v-else-if="order.isAllowRefund && goods.delivery_status == DeliveryStatusEnum.DELIVERED.value" class="action-btn"
              @click.stop="handleApplyRefund(goods.order_goods_id)">申请售后</view>
          </view>
        </view>
      </view>

      <!-- 订单信息 -->
      <view class="order-info i-card">
        <view class="info-item">
          <view class="item-lable">订单编号</view>
          <view class="item-content">
            <text>{{ order.order_no }}</text>
            <view class="act-copy" @click="handleCopy(order.order_no)">
              <text>复制</text>
            </view>
          </view>
        </view>
        <view class="info-item">
          <view class="item-lable">下单时间</view>
          <view class="item-content">
            <text>{{ order.create_time }}</text>
          </view>
        </view>
        <view class="info-item">
          <view class="item-lable">买家留言</view>
          <view class="item-content">
            <text>{{ order.buyer_remark ? order.buyer_remark : '--' }}</text>
          </view>
        </view>
      </view>

      <!-- 结算信息 -->
      <view class="trade-info i-card">
        <view class="info-item">
          <view class="item-lable">订单金额</view>
          <view class="item-content">
            <text>￥{{ order.total_price }}</text>
          </view>
        </view>
        <view v-if="order.coupon_money > 0" class="info-item">
          <view class="item-lable">优惠券抵扣</view>
          <view class="item-content">
            <text>-￥{{ order.coupon_money }}</text>
          </view>
        </view>
        <view v-if="order.points_money > 0" class="info-item">
          <view class="item-lable">{{ setting.points_name }}抵扣</view>
          <view class="item-content">
            <text>-￥{{ order.points_money }}</text>
          </view>
        </view>
        <view class="info-item">
          <view class="item-lable">运费</view>
          <view class="item-content">
            <text>+￥{{ order.express_price }}</text>
          </view>
        </view>
        <view v-if="order.update_price.value != '0.00'" class="info-item">
          <view class="item-lable">后台改价</view>
          <view class="item-content">
            <text>{{ order.update_price.symbol }}</text>
            <text>￥{{ order.update_price.value }}</text>
          </view>
        </view>
        <view class="divider"></view>
        <view class="trade-total">
          <text class="lable">实付款</text>
          <view class="goods-price">
            <text class="unit">￥</text>
            <text class="value">{{ order.pay_price }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- 底部操作按钮 -->
    <view v-if="order.order_status != OrderStatusEnum.CANCELLED.value" class="footer-fixed">
      <view class="btn-wrapper">
        <!-- 未支付取消订单 -->
        <block v-if="order.pay_status == PayStatusEnum.PENDING.value">
          <view class="btn-item" @click="onCancel(order.order_id)">取消</view>
        </block>
        <!-- 已支付进行中的订单 -->
        <block v-if="order.order_status != OrderStatusEnum.APPLY_CANCEL.value">
          <block v-if="order.pay_status == PayStatusEnum.SUCCESS.value && order.delivery_status == DeliveryStatusEnum.NOT_DELIVERED.value">
            <view class="btn-item" @click="onCancel(order.order_id)">申请取消</view>
          </block>
        </block>
        <!-- 已申请取消 -->
        <view v-else class="f-28 col-8">取消申请中</view>
        <!-- 未支付的订单 -->
        <block v-if="order.pay_status == PayStatusEnum.PENDING.value">
          <view class="btn-item active" @click="onPay(order.order_id)">去支付</view>
        </block>
        <!-- 确认收货 -->
        <block
          v-if="order.delivery_status == DeliveryStatusEnum.DELIVERED.value && order.receipt_status == ReceiptStatusEnum.NOT_RECEIVED.value">
          <view class="btn-item active" @click="onReceipt(order.order_id)">确认收货</view>
        </block>
        <!-- 订单评价 -->
        <block v-if="order.order_status == OrderStatusEnum.COMPLETED.value && order.is_comment == 0">
          <view class="btn-item" @click="handleTargetComment(order.order_id)">评价</view>
        </block>
      </view>
    </view>

  </view>
</template>

<script>
  import { inArray } from '@/utils/util'
  import {
    OrderTypeEnum,
    DeliveryStatusEnum,
    DeliveryTypeEnum,
    OrderStatusEnum,
    PayStatusEnum,
    ReceiptStatusEnum
  } from '@/common/enum/order'
  import ClientEnum from '@/common/enum/Client'
  import { PayMethodEnum } from '@/common/enum/payment'
  import { DeliveryMethodEnum } from '@/common/enum/order/delivery'
  import * as OrderApi from '@/api/order'

  // wx.onAppShow监听器
  let listener = false

  export default {
    data() {
      return {
        // 外部方法
        inArray,
        // 枚举类
        OrderTypeEnum,
        DeliveryStatusEnum,
        DeliveryTypeEnum,
        OrderStatusEnum,
        PayStatusEnum,
        ReceiptStatusEnum,
        DeliveryMethodEnum,
        PayMethodEnum,
        // 当前订单ID
        orderId: null,
        // 加载中
        isLoading: true,
        // 当前订单详情
        order: {},
        // 当前设置
        setting: {},
        // 控制onShow事件是否刷新订单信息
        canReset: false,
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad({ orderId }) {
      // 当前订单ID
      this.orderId = orderId
      // 获取当前订单信息
      this.getOrderDetail()
      // 注册全局事件订阅: 是否刷新当前订单数据
      uni.$on('syncRefresh', (val, isCur) => {
        if (!isCur) {
          this.canReset = val
        }
      })
      // #ifdef MP-WEIXIN
      // 微信小程序确认收货组件 - 回调监听
      this.listenerBusinessView()
      // #endif
    },

    /**
     * 生命周期函数--监听页面卸载
     */
    onUnload() {
      // #ifdef MP-WEIXIN
      wx.offAppShow(listener)
      // #endif
    },

    /**
     * 生命周期函数--监听页面显示
     */
    onShow() {
      this.canReset && this.getOrderDetail()
      this.canReset = false
    },

    methods: {

      // 获取当前订单信息
      getOrderDetail(canReset = false) {
        const app = this
        app.isLoading = true
        OrderApi.detail(app.orderId)
          .then(result => {
            app.order = result.data.order
            app.setting = result.data.setting
            app.isLoading = false
          })
        // 相应全局事件订阅: 刷新上级页面数据
        canReset && uni.$emit('syncRefresh', true, true)
      },

      // 复制指定内容
      handleCopy(value) {
        const app = this
        uni.setClipboardData({
          data: value,
          success: () => app.$toast('复制成功'),
          fail: ({ errMsg }) => app.$toast('复制失败 ' + errMsg)
        })
      },

      // 跳转到物流跟踪页面
      handleTargetExpress() {
        this.$navTo('pages/order/express/index', { orderId: this.orderId })
      },

      // 跳转到商品详情页面
      handleTargetGoods(goodsId) {
        this.$navTo('pages/goods/detail', { goodsId })
      },

      // 跳转到申请售后页面
      handleApplyRefund(orderGoodsId) {
        this.$navTo('pages/refund/apply', { orderGoodsId })
      },

      // 取消订单
      onCancel(orderId) {
        const app = this
        uni.showModal({
          title: '友情提示',
          content: '确认要取消该订单吗？',
          success(o) {
            if (o.confirm) {
              OrderApi.cancel(orderId)
                .then(result => {
                  // 显示成功信息
                  app.$toast(result.message)
                  // 刷新当前订单数据
                  setTimeout(() => app.getOrderDetail(true), 1500)
                })
            }
          }
        });
      },

      // 确认收货
      async onReceipt(orderId) {
        const app = this
        // 判断微信小程序端 - 同步发货信息管理
        if (
          app.platform === ClientEnum.MP_WEIXIN.value &&
          app.order.sync_weixin_shipping &&
          app.order.pay_method === PayMethodEnum.WECHAT.value &&
          app.order.platform === ClientEnum.MP_WEIXIN.value
        ) {
          // #ifdef MP-WEIXIN
          // 确认收货弹窗 (微信小程序提供的用于同步发货信息管理)
          app.openBusinessView()
          // #endif
        } else {
          // 确认收货弹窗 (系统默认)
          app.receiptModal(orderId)
        }
      },

      // 确认收货弹窗 (微信小程序提供的用于同步发货信息管理)
      // #ifdef MP-WEIXIN
      openBusinessView() {
        const app = this
        return new Promise((resolve, reject) => {
          // 引导用户升级微信版本
          if (!wx.openBusinessView) {
            console.log('不支持 wx.openBusinessView')
            resolve(false)
          }
          // 拉起确认收货组件
          wx.openBusinessView({
            businessType: 'weappOrderConfirm',
            extraData: { transaction_id: app.order.trade.trade_no },
            success() {
              // dosomething
              console.log('拉起确认收货组件 success')
              resolve(true)
            },
            fail(err) {
              //dosomething
              console.error('拉起确认收货组件 fail', err)
              resolve(false)
            }
          })
        })
      },

      // 微信小程序确认收货组件 - 回调监听
      listenerBusinessView() {
        // 这一步是为了防止出现多个监听器
        if (listener !== false) {
          wx.offAppShow(listener)
        }
        // 监听页面显示事件：微信小程序确认收货组件
        listener = wx.onAppShow(({ referrerInfo }) => {
          console.log('wx.onAppShow', this.orderId, referrerInfo.extraData)
          // referrerInfo.extraData.status = success 用户确认收货成功;  fail 用户确认收货失败; cancel 用户取消
          // 确认收货
          if (referrerInfo.extraData && referrerInfo.extraData.status && referrerInfo.extraData.status === 'success') {
            console.log('success receiptEvent', this.orderId)
            this.receiptEvent(this.orderId)
          }
        })
      },
      // #endif

      // 确认收货弹窗 (系统默认)
      receiptModal(orderId) {
        const app = this
        uni.showModal({
          title: '友情提示',
          content: '确认收到商品了吗？',
          success(o) {
            o.confirm && app.receiptEvent(orderId)
          }
        })
      },

      // 确认收货事件
      receiptEvent(orderId) {
        const app = this
        OrderApi.receipt(orderId)
          .then(result => {
            // 显示成功信息
            app.$success(result.message)
            // 刷新当前订单数据
            setTimeout(() => app.getOrderDetail(true), 1500)
          })
      },

      // 点击去支付
      onPay(orderId) {
        this.$navTo('pages/checkout/cashier/index', { orderId })
      },

      // 跳转到订单评价页
      handleTargetComment(orderId) {
        this.$navTo('pages/order/comment/index', { orderId })
      },

    },

  }
</script>

<style>
  page {
    background: #f4f4f4;
  }
</style>
<style lang="scss" scoped>
  .container {
    // 设置ios刘海屏底部横线安全区域
    padding-bottom: calc(constant(safe-area-inset-bottom) + 106rpx + 6rpx);
    padding-bottom: calc(env(safe-area-inset-bottom) + 106rpx + 6rpx);
  }

  // 页面顶部
  .header {
    display: flex;
    justify-content: space-between;
    background-color: #e8c269;
    height: 280rpx;
    padding: 56rpx 30rpx 0 30rpx;

    .order-status {
      display: flex;
      align-items: center;
      height: 128rpx;

      .status-icon {
        width: 128rpx;
        height: 128rpx;

        .image {
          display: block;
          width: 100%;
          height: 100%;
        }
      }

      .status-text {
        padding-left: 20rpx;
        color: #fff;
        font-size: 38rpx;
        font-weight: bold;
      }
    }

    .next-action {
      display: flex;
      align-items: center;
      height: 128rpx;

      .action-btn {
        min-width: 152rpx;
        height: 56rpx;
        padding: 0 30rpx;
        background-color: #fff;
        border-radius: 28rpx;
        border-color: rgb(102, 102, 102);
        cursor: pointer;
        user-select: none;
        color: #c7a157;
        display: flex;
        justify-content: center;
        align-items: center;
      }
    }
  }

  // 卡片区域
  .card-area {
    margin-top: -50rpx;
  }

  // 通栏卡片
  .i-card {
    background: #fff;
    padding: 24rpx 24rpx;
    width: 94%;
    box-shadow: 0 1rpx 5rpx 0px rgba(0, 0, 0, 0.05);
    margin: 0 auto 20rpx auto;
    border-radius: 20rpx;
  }

  // 收货地址
  .delivery-address {

    .link-man {
      line-height: 46rpx;
      color: #333;

      .name {
        margin-right: 10rpx;
      }
    }

    .address {
      margin-top: 12rpx;
      color: #999;
      font-size: 24rpx;

      .detail {
        margin-left: 6rpx;
      }
    }

  }

  // 物流公司
  .express {
    display: flex;
    align-items: center;

    .main {
      flex: 1;
    }

    .info-item {
      display: flex;
      margin-bottom: 20rpx;

      &:last-child {
        margin-bottom: 0;
      }

      .item-lable {
        display: flex;
        align-items: center;
        font-size: 26rpx;
        color: #999;
        margin-right: 30rpx;
      }

      .item-content {
        flex: 1;
        display: flex;
        align-items: center;
        font-size: 26rpx;
        color: #333;

        .act-copy {
          margin-left: 20rpx;
          padding: 2rpx 20rpx;
          font-size: 22rpx;
          color: #666;
          border: 1rpx solid #c1c1c1;
          border-radius: 16rpx;
        }
      }
    }

    // 右侧箭头
    .right-arrow {
      margin-left: 16rpx;
      font-size: 26rpx;
    }

  }

  // 商品列表
  .goods-list {

    // 商品项
    .goods-item {
      margin-bottom: 40rpx;

      &:last-child {
        margin-bottom: 0;
      }

      // 商品信息
      .goods-main {
        display: flex;
      }

      // 商品图片
      .goods-image {
        width: 180rpx;
        height: 180rpx;

        .image {
          display: block;
          width: 100%;
          height: 100%;
          border-radius: 8rpx;
        }
      }

      // 商品内容
      .goods-content {
        flex: 1;
        padding-left: 16rpx;
        padding-top: 16rpx;

        .goods-title {
          font-size: 26rpx;
          max-height: 76rpx;
        }

        .goods-props {
          margin-top: 14rpx;
          color: #ababab;
          font-size: 24rpx;
          overflow: hidden;

          .goods-props-item {
            padding: 4rpx 16rpx;
            border-radius: 12rpx;
            background-color: #fcfcfc;
          }
        }


      }

      // 交易信息
      .goods-trade {
        padding-top: 16rpx;
        width: 150rpx;
        text-align: right;
        color: $uni-text-color-grey;
        font-size: 26rpx;

        .goods-price {
          vertical-align: bottom;
          margin-bottom: 16rpx;

          .unit {
            margin-right: -2rpx;
            font-size: 24rpx;
          }
        }
      }

      // 商品售后
      .goods-refund {
        display: flex;
        justify-content: flex-end;

        .stata-text {
          font-size: 24rpx;
          color: #999;
        }

        .action-btn {
          border-radius: 28rpx;
          padding: 8rpx 26rpx;
          font-size: 24rpx;
          color: #383838;
          border: 1rpx solid #a8a8a8;
        }

      }

    }

  }

  // 订单信息
  .order-info {

    .info-item {
      display: flex;
      margin-bottom: 24rpx;

      &:last-child {
        margin-bottom: 0;
      }

      .item-lable {
        display: flex;
        align-items: center;
        font-size: 24rpx;
        color: #999;
        margin-right: 30rpx;
      }

      .item-content {
        flex: 1;
        display: flex;
        align-items: center;
        font-size: 26rpx;
        color: #333;

        .act-copy {
          margin-left: 20rpx;
          padding: 2rpx 20rpx;
          font-size: 22rpx;
          color: #666;
          border: 1rpx solid #c1c1c1;
          border-radius: 16rpx;
        }
      }
    }

  }

  // 交易信息
  .trade-info {

    .info-item {
      display: flex;
      margin-bottom: 24rpx;

      .item-lable {
        font-size: 24rpx;
        color: #999;
        margin-right: 24rpx;
      }

      .item-content {
        flex: 1;
        font-size: 26rpx;
        color: #333;
        text-align: right;
      }
    }

    .divider {
      height: 1rpx;
      background: #f1f1f1;
      margin-bottom: 24rpx;
    }

    .trade-total {
      display: flex;
      justify-content: flex-end;

      .lable {
        font-size: 26rpx;
      }

      .goods-price {
        // margin-left: 12rpx;
        vertical-align: bottom;
        color: $main-bg;
        font-size: 28rpx;

        .unit {
          margin-right: -2rpx;
          font-size: 24rpx;
        }
      }
    }

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
      height: 106rpx;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      padding: 0 30rpx;
    }

    .btn-item {
      min-width: 180rpx;
      border-radius: 30rpx;
      padding: 12rpx 26rpx;
      font-size: 28rpx;
      color: #383838;
      text-align: center;
      border: 1rpx solid #a8a8a8;
      margin-left: 24rpx;

      &.active {
        border: none;
        background: linear-gradient(to right, $main-bg, $main-bg2);
        color: $main-text;
      }
    }
  }
</style>