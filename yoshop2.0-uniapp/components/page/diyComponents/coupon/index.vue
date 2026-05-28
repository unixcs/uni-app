<template>
  <!-- 优惠券组 -->
  <view v-if="couponList.length" class="diy-coupon" :style="{ padding: `${itemStyle.paddingTop * 2}rpx 0`, background: itemStyle.background }">
    <scroll-view :scroll-x="true">
      <view class="coupon-wrapper">
        <view class="coupon-item" :class="{ disable: !dataItem.state.value }" v-for="(dataItem, index) in couponList" :key="index"
          :style="{ marginRight: `${itemStyle.marginRight * 2}rpx` }">
          <text class="before" :style="{ background: itemStyle.background }"></text>
          <view class="left-content" :style="{ background: itemStyle.couponBgColor, color: itemStyle.couponTextColor }">
            <view class="content-top">
              <template v-if="dataItem.coupon_type == 10">
                <text class="unit">￥</text>
                <text class="price">{{ dataItem.reduce_price }}</text>
              </template>
              <template v-if="dataItem.coupon_type == 20">
                <text class="price">{{ dataItem.discount }}折</text>
              </template>
            </view>
            <view class="content-bottom">
              <text class="f-22">满{{ dataItem.min_price }}元可用</text>
            </view>
          </view>
          <view class="right-receive" :style="{ background: itemStyle.receiveBgColor, color: itemStyle.receiveTextColor }"
            @click="handleReceive(index, dataItem)">
            <block v-if="dataItem.state.value">
              <text>立即</text>
              <text>领取</text>
            </block>
            <text v-else>{{ dataItem.state.text }}</text>
          </view>
        </view>
      </view>
    </scroll-view>
  </view>
</template>

<script>
  import mixin from '../mixin'
  import * as MyCouponApi from '@/api/myCoupon'
  import { cloneObj } from '@/utils/util'

  export default {

    /**
     * 组件的属性列表
     * 用于组件自定义设置
     */
    props: {
      itemIndex: String,
      itemStyle: Object,
      params: Object,
      dataList: Array
    },

    data() {
      return {
        // 优惠券列表
        couponList: [],
        // 防止重复提交
        disable: false
      }
    },
    watch: {
      // 这里监听dataList并写入到data中, 因为领取事件不能直接修改props中的属性
      dataList: {
        handler(data) {
          this.couponList = cloneObj(data)
          // console.log(this.couponList)
        },
        immediate: true,
        deep: true
      }
    },

    mixins: [mixin],

    /**
     * 组件的方法列表
     * 更新属性和数据的方法与更新页面数据的方法类似
     */
    methods: {

      // 立即领取事件
      handleReceive(index, item) {
        const app = this
        if (app.disable || !item.state.value|| !item.coupon_id) {
          return
        }
        app.disable = true
        MyCouponApi.receive(item.coupon_id, {}, { load: false })
          .then(result => {
            // 显示领取成功提示
            app.$success(result.message)
            // 将优惠券设置为已领取
            app.setReceived(index, item)
          })
          .finally(() => app.disable = false)
      },

      // 将优惠券设置为已领取
      setReceived(index, item) {
        const app = this
        // #ifdef VUE2
        app.$set(app.couponList, index, {
          ...item,
          state: { value: 0, text: '已领取' }
        })
        // #endif
        // #ifdef VUE3
        app.couponList[index] = {
          ...item,
          state: { value: 0, text: '已领取' }
        }
        // #endif
      }

    }

  }
</script>

<style lang="scss" scoped>
  .diy-coupon {

    .coupon-wrapper {
      display: flex;
      width: max-content;
      height: 130rpx;
      padding: 0 24rpx;

      &::-webkit-scrollbar {
        display: none;
      }
    }
  }

  .coupon-item {
    flex-shrink: 0;
    width: 300rpx;
    height: 130rpx;
    position: relative;
    color: #fff;
    overflow: hidden;
    box-sizing: border-box;
    margin-right: 40rpx;
    display: flex;

    &:last-child {
      margin-right: 0 !important;
    }

    &.disable {
      .left-content {
        background: linear-gradient(-113deg, #bdbdbd, #a2a1a2) !important;
      }

      .right-receive {
        background-color: #949494 !important;
      }
    }

    .before {
      content: "";
      position: absolute;
      z-index: 1;
      width: 32rpx;
      height: 32rpx;
      top: 45%;
      left: -16rpx;
      transform: translateY(-50%);
      border-radius: 80%;
      background-color: #fff;
    }

    .left-content {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      position: relative;
      width: 70%;
      height: 100%;
      background-color: #E5004F;
      font-size: 24rpx;

      .content-top {
        .unit {
          font-size: 15px;
        }

        .price {
          font-size: 44rpx;
        }
      }
    }

    .right-receive {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      width: 30%;
      height: 100%;
      background-color: #4e4e4e;
      text-align: center;
      font-size: 24rpx;
    }
  }
</style>