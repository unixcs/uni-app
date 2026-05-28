<template>
  <view v-if="!isLoading && express.length" class="container">

    <!-- 标签栏 -->
    <u-tabs v-if="tabs.length > 1" :list="tabs" :isScroll="true" v-model="curTab" :active-color="appTheme.mainBg" :duration="0.2" bar-width="60"
      @change="onChangeTab"></u-tabs>

    <!-- 商品列表 -->
    <view v-show="tabs.length > 1" class="deliver-goods-list i-card clearfix">
      <view class="goods-item" v-for="(goods, idx) in express[curTab].goods" :key="idx">
        <image class="goods-img" :src="goods.goods.goods_image" alt="商品图片" />
        <view class="title">共{{ goods.delivery_num }}件</view>
      </view>
    </view>

    <!-- 物流信息 -->
    <view class="express i-card">
      <view class="info-item">
        <view class="item-lable">物流公司：</view>
        <view class="item-content">
          <text v-if="express[curTab].delivery_method == 20">无需物流</text>
          <text v-else>{{ express[curTab].express ? express[curTab].express.express_name : '--' }}</text>
        </view>
      </view>
      <view class="info-item">
        <view class="item-lable">物流单号：</view>
        <view class="item-content">
          <text>{{ express[curTab].express_no ? express[curTab].express_no : '--' }}</text>
          <view v-show="express[curTab].express_no" class="act-copy" @click.stop="handleCopy(express[curTab].express_no)">
            <text>复制</text>
          </view>
        </view>
      </view>
    </view>

    <!-- 物流轨迹 -->
    <view class="logis-detail" v-if="express[curTab].traces && express[curTab].traces.length">
      <view class="logis-item" :class="{ first: index === 0 }" v-for="(item, index) in express[curTab].traces" :key="index">
        <view class="logis-item-content">
          <view class="logis-item-content__describe">
            <text class="f-26">{{ item.context }}</text>
          </view>
          <view class="logis-item-content__time">
            <text class="f-22">{{ item.time }}</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
  import * as OrderApi from '@/api/order'

  export default {
    data() {
      return {
        // 正在加载
        isLoading: true,
        // 当前标签索引
        curTab: 0,
        // 当前订单ID
        orderId: null,
        // 物流信息
        express: {}
      }
    },
    computed: {
      tabs() {
        if (this.express && this.express.length) {

          const test = this.express.map((item, index) => {
            return { name: `包裹${index + 1}` }
          })

          console.log('test', test)

          return this.express.map((item, index) => {
            return { name: `包裹${index + 1}` }
          })
        }
        return []
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad({ orderId }) {
      this.orderId = orderId
      // 获取当前订单的物流信息
      this.getExpress()
    },

    methods: {

      // 获取当前订单的物流信息
      getExpress() {
        const app = this
        app.isLoading = true
        OrderApi.express(app.orderId)
          .then(result => {
            app.express = result.data.express
            app.isLoading = false
          })
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

      // 切换标签项
      onChangeTab(index) {
        this.curTab = index
      },

    }
  }
</script>

<style lang="scss" scoped>

  // 通栏卡片
  .i-card {
    background: #fff;
    padding: 24rpx 24rpx;
  }

  // 物流公司
  .express {
    margin-bottom: 20rpx;

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
        margin-right: 6rpx;
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

  // 商品列表
  .deliver-goods-list {
    margin-top: 20rpx;
    margin-bottom: -30rpx;

    .goods-item {
      position: relative;
      border-radius: 8rpx;
      overflow: hidden;
      width: 130rpx;
      height: 130rpx;
      float: left;
      margin-right: 30rpx;
      margin-bottom: 30rpx;
    }

    .goods-img {
      display: block;
      width: 100%;
      height: 100%;
    }

    .title {
      position: absolute;
      bottom: 0;
      width: 100%;
      text-align: center;
      background: rgba(0, 0, 0, 0.6);
      color: #fff;
      padding: 4rpx 0;
      font-size: 24rpx;
    }
  }

  // 物流轨迹
  .logis-detail {
    padding: 30rpx;
    background-color: #fff;

    .logis-item {
      position: relative;
      padding: 10px 0 10px 25px;
      box-sizing: border-box;
      border-left: 2px solid #ccc;

      &.first {
        border-left: 2px solid #f40;

        &:after {
          background: #f40;
        }

        .logis-item-content {
          background: #ff6e39;
          color: #fff;

          &:after {
            border-bottom-color: #ff6e39;
          }
        }
      }

      &:after {
        content: ' ';
        display: inline-block;
        position: absolute;
        left: -6px;
        top: 30px;
        width: 6px;
        height: 6px;
        border-radius: 10px;
        background: #bdbdbd;
        border: 2px solid #fff;
      }

      .logis-item-content {
        position: relative;
        background: #f9f9f9;
        padding: 10rpx 20rpx;
        box-sizing: border-box;
        color: #666;

        &:after {
          content: '';
          display: inline-block;
          position: absolute;
          left: -10px;
          top: 18px;
          border-left: 10px solid #fff;
          border-bottom: 10px solid #f3f3f3;
        }
      }
    }
  }
</style>