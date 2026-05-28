<template>
  <view class="container" :style="appThemeStyle">
    <mescroll-body ref="mescrollRef" :sticky="true" @init="mescrollInit" :down="{ native: true }" @down="downCallback" :up="upOption"
      @up="upCallback">
      <!-- tab栏 -->
      <u-tabs :list="tabs" :is-scroll="false" v-model="curTab" :active-color="appTheme.mainBg" :duration="0.2" @change="onChangeTab" />
      <!-- 订单列表 -->
      <view class="order-list">
        <view class="order-item" v-for="(item, index) in list.data" :key="index">
          <view class="item-top">
            <view class="item-top-left">
              <text class="order-time">{{ item.create_time }}</text>
            </view>
            <view class="item-top-right">
              <text class="state-text">{{ item.state_text }}</text>
            </view>
          </view>
          <!-- 商品列表 -->
          <view class="goods-list" @click="handleTargetDetail(item.order_id)">
            <view class="goods-item" v-for="(goods, idx) in item.goods" :key="idx">
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
          </view>
          <!-- 订单合计 -->
          <view class="order-total">
            <text>共{{ item.total_num }}件商品，总金额</text>
            <text class="unit">￥</text>
            <text class="money">{{ item.pay_price }}</text>
          </view>
          <!-- 订单操作 -->
          <view v-if="item.order_status != OrderStatusEnum.CANCELLED.value" class="order-handle">
            <view class="btn-group clearfix">
              <!-- 未支付取消订单 -->
              <block v-if="item.pay_status == PayStatusEnum.PENDING.value">
                <view class="btn-item" @click="onCancel(item.order_id)">取消</view>
              </block>
              <!-- 已支付进行中的订单 -->
              <block v-if="item.order_status != OrderStatusEnum.APPLY_CANCEL.value">
                <block
                  v-if="item.pay_status == PayStatusEnum.SUCCESS.value && item.delivery_status == DeliveryStatusEnum.NOT_DELIVERED.value">
                  <view class="btn-item" @click="onCancel(item.order_id)">申请取消</view>
                </block>
              </block>
              <!-- 已申请取消 -->
              <view v-else class="f-28 col-8">取消申请中</view>
              <!-- 未支付的订单 -->
              <block v-if="item.pay_status == PayStatusEnum.PENDING.value">
                <view class="btn-item active" @click="onPay(item.order_id)">去支付</view>
              </block>
              <!-- 确认收货 -->
              <block
                v-if="item.delivery_status == DeliveryStatusEnum.DELIVERED.value && item.receipt_status == ReceiptStatusEnum.NOT_RECEIVED.value">
                <view class="btn-item active" @click="onReceipt(index)">确认收货</view>
              </block>
              <!-- 订单评价 -->
              <block v-if="item.order_status == OrderStatusEnum.COMPLETED.value && item.is_comment == 0">
                <view class="btn-item" @click="handleTargetComment(item.order_id)">评价</view>
              </block>
            </view>
          </view>
        </view>
      </view>
    </mescroll-body>
  </view>
</template>

<script>
  import {
    OrderSourceEnum,
    DeliveryStatusEnum,
    DeliveryTypeEnum,
    OrderStatusEnum,
    PayStatusEnum,
    ReceiptStatusEnum
  } from '@/common/enum/order'
  import ClientEnum from '@/common/enum/Client'
  import { PayMethodEnum } from '@/common/enum/payment'
  import MescrollMixin from '@/uni_modules/mescroll-uni/components/mescroll-uni/mescroll-mixins'
  import { getEmptyPaginateObj, getMoreListData } from '@/core/app'
  import * as OrderApi from '@/api/order'

  // 每页记录数量
  const pageSize = 15

  // tab栏数据
  const tabs = [{
    name: `全部`,
    value: 'all'
  }, {
    name: `待支付`,
    value: 'payment'
  }, {
    name: `待发货`,
    value: 'delivery'
  }, {
    name: `待收货`,
    value: 'received'
  }, {
    name: `待评价`,
    value: 'comment'
  }]

  // wx.onAppShow监听器
  let listener = false

  export default {
    mixins: [MescrollMixin],
    data() {
      return {
        // 枚举类
        OrderSourceEnum,
        DeliveryStatusEnum,
        DeliveryTypeEnum,
        OrderStatusEnum,
        PayStatusEnum,
        ReceiptStatusEnum,
        PayMethodEnum,
        // 当前页面参数
        options: { dataType: 'all', orderSource: null },
        // tab栏数据
        tabs,
        // 当前标签索引
        curTab: 0,
        // 订单列表数据
        list: getEmptyPaginateObj(),
        // 上拉加载配置
        upOption: {
          // 首次自动执行
          auto: true,
          // 每页数据的数量; 默认10
          page: { size: pageSize },
          // 数量要大于4条才显示无更多数据
          noMoreSize: 4,
          // 空布局
          empty: { tip: '亲，暂无订单记录' }
        },
        // 控制onShow事件是否刷新订单列表
        canReset: false
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad(options) {
      // 记录query参数
      this.options = { ...this.options, ...options }
      // 初始化当前选中的标签
      this.initCurTab()
      // 注册全局事件订阅: 是否刷新订单列表
      uni.$on('syncRefresh', canReset => {
        this.canReset = canReset
      })
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
      this.canReset && this.onRefreshList()
      this.canReset = false
    },

    /**
     * 生命周期函数--监听页面的卸载
     */
    onUnload() {
      // 卸载全局事件订阅
      uni.$off('syncRefresh')
    },

    methods: {

      // 初始化当前选中的标签
      initCurTab() {
        const { options } = this
        if (options.dataType) {
          const index = this.tabs.findIndex(item => item.value == options.dataType)
          this.curTab = index > -1 ? index : 0
        }
      },

      /**
       * 上拉加载的回调 (页面初始化时也会执行一次)
       * 其中page.num:当前页 从1开始, page.size:每页数据条数,默认10
       * @param {Object} page
       */
      upCallback(page) {
        const app = this
        // 设置列表数据
        app.getOrderList(page.num)
          .then(list => {
            const curPageLen = list.data.length
            const totalSize = list.total
            app.mescroll.endBySize(curPageLen, totalSize)
          })
          .catch(() => app.mescroll.endErr())
      },

      // 获取订单列表
      getOrderList(pageNo = 1) {
        const app = this
        return new Promise((resolve, reject) => {
          OrderApi.list({
              dataType: app.getTabValue(),
              orderSource: app.options.orderSource,
              page: pageNo,
            }, { load: false })
            .then(result => {
              // 合并新数据
              const newList = app.initList(result.data.list)
              app.list.data = getMoreListData(newList, app.list, pageNo)
              resolve(newList)
            })
        })
      },

      // 初始化订单列表数据
      initList(newList) {
        newList.data.forEach(item => {
          item.total_num = 0
          item.goods.forEach(goods => {
            item.total_num += goods.total_num
          })
        })
        return newList
      },

      // 获取当前标签项的值
      getTabValue() {
        return this.tabs[this.curTab].value
      },

      // 切换标签项
      onChangeTab(index) {
        const app = this
        // 设置当前选中的标签
        app.curTab = index
        // 刷新订单列表
        app.onRefreshList()
      },

      // 刷新订单列表
      onRefreshList() {
        this.list = getEmptyPaginateObj()
        setTimeout(() => {
          this.mescroll.resetUpScroll()
        }, 120)
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
                  // 刷新订单列表
                  app.onRefreshList()
                })
            }
          }
        });
      },

      // 确认收货
      async onReceipt(orderIndex) {
        const app = this
        const orderItem = app.list.data[orderIndex]
        // 判断微信小程序端 - 同步发货信息管理
        if (
          app.platform === ClientEnum.MP_WEIXIN.value &&
          orderItem.sync_weixin_shipping &&
          orderItem.pay_method === PayMethodEnum.WECHAT.value &&
          orderItem.platform === ClientEnum.MP_WEIXIN.value
        ) {
          // #ifdef MP-WEIXIN
          // 微信小程序确认收货组件 - 回调监听
          this.listenerBusinessView(orderItem.order_id)
          // 确认收货弹窗 (微信小程序提供的用于同步发货信息管理)
          app.openBusinessView(orderItem)
          // #endif
        } else {
          // 确认收货弹窗 (系统默认)
          app.receiptModal(orderItem.order_id)
        }
      },

      // 确认收货弹窗 (微信小程序提供的用于同步发货信息管理)
      // #ifdef MP-WEIXIN
      openBusinessView(orderItem) {
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
            extraData: { transaction_id: orderItem.trade.trade_no },
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
      listenerBusinessView(orderId) {
        // 这一步是为了防止出现多个监听器
        if (listener !== false) {
          wx.offAppShow(listener)
        }
        // 监听页面显示事件：微信小程序确认收货组件
        listener = wx.onAppShow(({ referrerInfo }) => {
          console.log('wx.onAppShow', orderId, referrerInfo.extraData)
          // referrerInfo.extraData.status = success 用户确认收货成功;  fail 用户确认收货失败; cancel 用户取消
          // 确认收货
          if (referrerInfo.extraData && referrerInfo.extraData.status && referrerInfo.extraData.status === 'success') {
            console.log('success receiptEvent', orderId)
            this.receiptEvent(orderId)
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
            // 刷新订单列表
            app.onRefreshList()
          })
      },

      // 点击去支付
      onPay(orderId) {
        this.$navTo('pages/checkout/cashier/index', { orderId })
      },

      // 跳转到订单详情页
      handleTargetDetail(orderId) {
        this.$navTo('pages/order/detail', { orderId })
      },

      // 跳转到订单评价页
      handleTargetComment(orderId) {
        this.$navTo('pages/order/comment/index', { orderId })
      }

    },

  }
</script>

<style lang="scss" scoped>
  // 项目内容
  .order-item {
    margin: 20rpx auto 20rpx auto;
    padding: 30rpx 30rpx;
    width: 94%;
    box-shadow: 0 1rpx 5rpx 0px rgba(0, 0, 0, 0.05);
    border-radius: 16rpx;
    background: #fff;
  }

  // 项目顶部
  .item-top {
    display: flex;
    justify-content: space-between;
    font-size: 26rpx;
    margin-bottom: 40rpx;

    .order-time {
      color: #777;
    }

    .state-text {
      color: $main-bg;
    }
  }

  // 商品列表
  .goods-list {

    // 商品项
    .goods-item {
      display: flex;
      margin-bottom: 40rpx;

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

    }

  }

  // 订单合计
  .order-total {
    font-size: 26rpx;
    vertical-align: bottom;
    text-align: right;
    height: 40rpx;
    margin-bottom: 30rpx;

    .unit {
      margin-left: 8rpx;
      margin-right: -2rpx;
      font-size: 26rpx;
    }

    .money {
      font-size: 28rpx;
    }
  }

  // 订单操作
  .order-handle {
    .btn-group {

      .btn-item {
        border-radius: 10rpx;
        padding: 8rpx 20rpx;
        margin-left: 15rpx;
        font-size: 26rpx;
        float: right;
        color: #383838;
        border: 1rpx solid #a8a8a8;

        &:last-child {
          margin-left: 0;
        }

        &.active {
          color: $main-bg;
          border: 1rpx solid $main-bg;
        }
      }

    }

  }
</style>