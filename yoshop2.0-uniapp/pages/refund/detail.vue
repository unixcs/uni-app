<template>
  <view v-if="!isLoading" class="container" :style="appThemeStyle">
    <view class="detail-header dis-flex flex-y-center">
      <view class="header-backdrop">
        <image class="image" src="/static/order/refund-bg.png"></image>
      </view>
      <view class="header-state">
        <text class="f-32 col-f">{{ detail.service_state_text || '--' }}</text>
      </view>
    </view>

    <view class="detail-goods b-f m-top20 dis-flex flex-dir-row" @click="onGoodsDetail(detail.orderGoods.goods_id)">
      <view class="left"><image class="goods-image" :src="detail.orderGoods.goods_image"></image></view>
      <view class="right dis-flex flex-box flex-dir-column flex-x-around">
        <view class="goods-name"><text class="twoline-hide">{{ detail.orderGoods.goods_name }}</text></view>
        <view class="dis-flex col-9 f-24">
          <view class="flex-box">
            <view class="goods-props clearfix">
              <view class="goods-props-item" v-for="(props, idx) in detail.orderGoods.goods_props" :key="idx"><text>{{ props.value.name }}</text></view>
            </view>
          </view>
          <text class="t-r">×{{ detail.orderGoods.total_num }}</text>
        </view>
      </view>
    </view>

    <view class="detail-order b-f row-block">
      <view class="item dis-flex flex-x-end flex-y-center">
        <text>退款金额：</text>
        <text class="col-m">￥{{ detail.refund_money || detail.orderGoods.total_pay_price }}</text>
      </view>
    </view>

    <view class="detail-refund b-f m-top20">
      <view class="detail-refund__row dis-flex">
        <view class="text"><text>退款原因：</text></view>
        <view class="flex-box"><text>{{ detail.apply_desc || '--' }}</text></view>
      </view>
      <view v-if="detail.refuse_desc" class="detail-refund__row dis-flex">
        <view class="text"><text>处理备注：</text></view>
        <view class="flex-box"><text>{{ detail.refuse_desc }}</text></view>
      </view>
      <view v-if="detail.images && detail.images.length > 0" class="detail-refund__row dis-flex">
        <view class="text"><text>申请凭证：</text></view>
        <view class="image-list flex-box">
          <view class="image-preview" v-for="(item, index) in detail.images" :key="index">
            <image class="image" mode="aspectFill" :src="item.image_url" @click="handlePreviewImages(index)"></image>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
  import * as RefundApi from '@/api/refund'

  export default {
    data() {
      return {
        isLoading: true,
        orderRefundId: null,
        detail: {
          orderGoods: {}
        }
      }
    },
    onLoad({ orderRefundId }) {
      this.orderRefundId = orderRefundId
      this.getPageData()
    },
    methods: {
      getPageData() {
        this.isLoading = true
        RefundApi.detail(this.orderRefundId).then(result => {
          this.detail = result.data.detail || { orderGoods: {} }
          this.isLoading = false
        })
      },
      onGoodsDetail(goodsId) {
        if (!goodsId) return
        this.$navTo('pages/goods/detail', { goodsId })
      },
      handlePreviewImages(index) {
        const images = (this.detail.images || []).map(item => item.image_url)
        uni.previewImage({ current: images[index], urls: images })
      }
    }
  }
</script>

<style lang="scss" scoped>
  .detail-header { position: relative; width: 100%; height: 140rpx; .header-backdrop { width: 100%; position: absolute; top: 0; left: 0; z-index: 0; .image { display: block; width: 100%; height: 140rpx; } } .header-state { z-index: 1; padding: 0 50rpx; } }
  .detail-goods { padding: 24rpx 20rpx; .left .goods-image { display: block; width: 150rpx; height: 150rpx; } .right { padding-left: 20rpx; } .goods-props { margin-top: 14rpx; color: #ababab; font-size: 24rpx; overflow: hidden; .goods-props-item { padding: 4rpx 16rpx; border-radius: 12rpx; background-color: #fcfcfc; } } }
  .detail-order { padding: 15rpx 20rpx; font-size: 26rpx; }
  .detail-refund { padding: 15rpx 20rpx; .detail-refund__row { margin: 20rpx 0; } }
  .image-list { margin-bottom: -15rpx; .image-preview { margin: 0 15rpx 15rpx 0; float: left; .image { display: block; width: 180rpx; height: 180rpx; } &:nth-child(3n+0) { margin-right: 0; } } }
</style>
