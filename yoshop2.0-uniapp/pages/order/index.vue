<template>
  <view class="container" :style="appThemeStyle">
    <mescroll-body ref="mescrollRef" :sticky="true" @init="mescrollInit" :down="{ native: true }" @down="downCallback" :up="upOption" @up="upCallback">
      <u-tabs :list="tabs" :is-scroll="false" v-model="curTab" :active-color="appTheme.mainBg" :duration="0.2" @change="onChangeTab" />
      <view class="order-list">
        <view class="order-item" v-for="(item, index) in list.data" :key="index">
          <view class="item-top">
            <view class="item-top-left"><text class="order-time">{{ item.create_time }}</text></view>
            <view class="item-top-right"><text class="state-text">{{ getOrderStateText(item) }}</text></view>
          </view>
          <view class="goods-list" @click="handleTargetDetail(item.order_id)">
            <view class="goods-item" v-for="(goods, idx) in item.goods" :key="idx">
              <view class="goods-image"><image class="image" :src="goods.goods_image || (goods.image && goods.image.preview_url)" mode="scaleToFill"></image></view>
              <view class="goods-content">
                <view class="goods-title"><text class="twoline-hide">{{ goods.goods_name }}</text></view>
                <view class="goods-props clearfix">
                  <view class="goods-props-item" v-for="(props, pidx) in goods.goods_props" :key="pidx"><text>{{ props.value.name }}</text></view>
                </view>
              </view>
              <view class="goods-trade">
                <view class="goods-price"><text class="unit">￥</text><text class="value">{{ goods.is_user_grade ? goods.grade_goods_price : goods.goods_price }}</text></view>
                <view class="goods-num"><text>×{{ goods.total_num }}</text></view>
              </view>
            </view>
          </view>
          <view class="order-total"><text>共{{ item.total_num }}件商品，总金额</text><text class="unit">￥</text><text class="money">{{ item.pay_price }}</text></view>
          <view class="order-handle">
            <view class="btn-group clearfix">
              <block v-if="item.action_flags && item.action_flags.can_cancel">
                <view class="btn-item" @click="onCancel(item.order_id)">取消</view>
                <view class="btn-item active" @click="onPay(item.order_id)">去支付</view>
              </block>
            </view>
          </view>
        </view>
      </view>
    </mescroll-body>
  </view>
</template>

<script>
  import { OrderSourceEnum, OrderStatusEnum, PayStatusEnum } from '@/common/enum/order'
  import MescrollMixin from '@/uni_modules/mescroll-uni/components/mescroll-uni/mescroll-mixins'
  import { getEmptyPaginateObj, getMoreListData } from '@/core/app'
  import * as OrderApi from '@/api/order'

  const pageSize = 15
  const tabs = [
    { name: '全部', value: 'all' },
    { name: '待支付', value: 'payment' },
    { name: '待联系', value: 'contact' },
    { name: '服务中', value: 'in_service' },
    { name: '已完成', value: 'complete' }
  ]

  export default {
    mixins: [MescrollMixin],
    data() {
      return {
        OrderSourceEnum,
        OrderStatusEnum,
        PayStatusEnum,
        options: { dataType: 'all', orderSource: null },
        tabs,
        curTab: 0,
        list: getEmptyPaginateObj(),
        upOption: { auto: true, page: { size: pageSize }, noMoreSize: 4, empty: { tip: '亲，暂无服务订单记录' } },
        canReset: false
      }
    },
    onLoad(options) {
      this.options = { ...this.options, ...options }
      this.initCurTab()
      uni.$on('syncRefresh', canReset => { this.canReset = canReset })
    },
    onShow() { this.canReset && this.onRefreshList(); this.canReset = false },
    onUnload() { uni.$off('syncRefresh') },
    methods: {
      initCurTab() { const index = this.tabs.findIndex(item => item.value == this.options.dataType); this.curTab = index > -1 ? index : 0 },
      upCallback(page) { this.getOrderList(page.num).then(list => { this.mescroll.endBySize(list.data.length, list.total) }).catch(() => this.mescroll.endErr()) },
      getOrderList(pageNo = 1) { return new Promise(resolve => { OrderApi.list({ dataType: this.getTabValue(), orderSource: this.options.orderSource, page: pageNo }, { load: false }).then(result => { const newList = this.initList(result.data.list); this.list.data = getMoreListData(newList, this.list, pageNo); resolve(newList) }) }) },
      initList(newList) { newList.data.forEach(item => { item.total_num = 0; item.goods.forEach(goods => { item.total_num += goods.total_num }) }); return newList },
      getTabValue() { return this.tabs[this.curTab].value },
      getOrderStateText(item) { return (item.refund_info && item.refund_info.service_state_text) || item.service_state_text || '--' },
      onChangeTab(index) { this.curTab = index; this.onRefreshList() },
      onRefreshList() { this.list = getEmptyPaginateObj(); setTimeout(() => { this.mescroll.resetUpScroll() }, 120) },
      onCancel(orderId) { uni.showModal({ title: '友情提示', content: '确认要取消该订单吗？', success: o => { if (o.confirm) OrderApi.cancel(orderId).then(result => { this.$toast(result.message); this.onRefreshList() }) } }) },
      onPay(orderId) { this.$navTo('pages/checkout/cashier/index', { orderId }) },
      handleTargetDetail(orderId) { this.$navTo('pages/order/detail', { orderId }) }
    }
  }
</script>

<style lang="scss" scoped>
.order-item { margin: 20rpx auto; padding: 30rpx; width: 94%; box-shadow: 0 1rpx 5rpx 0 rgba(0,0,0,.05); border-radius: 16rpx; background: #fff; }
.item-top { display: flex; justify-content: space-between; font-size: 26rpx; margin-bottom: 40rpx; .order-time { color: #777; } .state-text { color: $main-bg; } }
.goods-list .goods-item { display: flex; margin-bottom: 40rpx; .goods-image { width: 180rpx; height: 180rpx; .image { width: 100%; height: 100%; border-radius: 8rpx; } } .goods-content { flex: 1; padding-left: 16rpx; padding-top: 16rpx; .goods-title { font-size: 26rpx; max-height: 76rpx; } .goods-props { margin-top: 14rpx; color: #ababab; font-size: 24rpx; overflow: hidden; .goods-props-item { padding: 4rpx 16rpx; border-radius: 12rpx; background-color: #fcfcfc; } } } .goods-trade { padding-top: 16rpx; width: 150rpx; text-align: right; color: $uni-text-color-grey; font-size: 26rpx; } }
.order-total { font-size: 26rpx; text-align: right; height: 40rpx; margin-bottom: 30rpx; .unit { margin-left: 8rpx; margin-right: -2rpx; font-size: 26rpx; } .money { font-size: 28rpx; } }
.order-handle .btn-group .btn-item { border-radius: 10rpx; padding: 8rpx 20rpx; margin-left: 15rpx; font-size: 26rpx; float: right; color: #383838; border: 1rpx solid #a8a8a8; &.active { color: $main-bg; border-color: $main-bg; } }
</style>
