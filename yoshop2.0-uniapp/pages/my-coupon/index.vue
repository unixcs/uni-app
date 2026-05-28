<template>
  <view class="container" :style="appThemeStyle">
    <mescroll-body ref="mescrollRef" :sticky="true" @init="mescrollInit" :down="{ use: false }" :up="upOption" @up="upCallback">
      <!-- tab栏 -->
      <u-tabs :list="tabs" :is-scroll="false" v-model="curTab" :active-color="appTheme.mainBg" :duration="0.2" @change="onChangeTab" />
      <!-- 优惠券列表 -->
      <view class="coupon-list">
        <view class="coupon-item" v-for="(item, index) in list.data" :key="index">
          <view class="item-wrapper" :class="[ !item.state.value ? 'disable' : '' ]">
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
              <view v-if="item.describe" class="coupon-expand" @click="handleDescribe(index)">
                <text>使用说明</text>
                <text class="coupon-expand-arrow iconfont icon-arrow-down" :class="[item.expand ? 'expand' : '']" />
              </view>
            </view>
            <view class="coupon-right">
              <view v-if="!item.state.value" class="state-text">
                <text>{{ item.state.text }}</text>
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
    </mescroll-body>
  </view>
</template>

<script>
  import MescrollMixin from '@/uni_modules/mescroll-uni/components/mescroll-uni/mescroll-mixins'
  import { getEmptyPaginateObj, getMoreListData } from '@/core/app'
  import * as MyCouponApi from '@/api/myCoupon'
  import { CouponTypeEnum } from '@/common/enum/coupon'

  const pageSize = 15
  const tabs = [{
    name: `未使用`,
    value: 'isUnused'
  }, {
    name: `已使用`,
    value: 'isUse'
  }, {
    name: `已过期`,
    value: 'isExpire'
  }]

  export default {
    mixins: [MescrollMixin],
    data() {
      return {
        // 枚举类
        CouponTypeEnum,
        // 标签栏数据
        tabs,
        // 当前标签索引
        curTab: 0,
        // 优惠券列表数据
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
          empty: {
            tip: '亲，暂无相关优惠券'
          }
        }
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad(options) {

    },

    methods: {

      /**
       * 上拉加载的回调 (页面初始化时也会执行一次)
       * 其中page.num:当前页 从1开始, page.size:每页数据条数,默认10
       * @param {Object} page
       */
      upCallback(page) {
        const app = this
        // 设置列表数据
        app.getCouponList(page.num)
          .then(list => {
            const curPageLen = list.data.length
            const totalSize = list.total
            app.mescroll.endBySize(curPageLen, totalSize)
          })
          .catch(() => app.mescroll.endErr())
      },

      // 获取优惠券列表
      getCouponList(pageNo = 1) {
        const app = this
        return new Promise((resolve, reject) => {
          MyCouponApi.list({ dataType: app.getTabValue(), page: pageNo }, { load: false })
            .then(result => {
              // 合并新数据
              const newList = result.data.list
              app.list.data = getMoreListData(newList, app.list, pageNo)
              resolve(newList)
            })
        })
      },

      // 评分类型
      getTabValue() {
        return this.tabs[this.curTab].value
      },

      // 切换标签项
      onChangeTab(index) {
        const app = this
        // 设置当前选中的标签
        app.curTab = index
        // 刷新优惠券列表
        app.onRefreshList()
      },

      // 刷新优惠券列表
      onRefreshList() {
        this.list = getEmptyPaginateObj()
        setTimeout(() => {
          this.mescroll.resetUpScroll()
        }, 120)
      },

      // 展开优惠券描述
      handleDescribe(index) {
        this.list.data[index].expand = !this.list.data[index].expand
      }
    }
  }
</script>

<style lang="scss" scoped>
  .coupon-list {
    padding: 20rpx;
  }

  .coupon-item {
    margin-bottom: 22rpx;
    font-size: 24rpx;
    background: #fff;
    // min-height: 178rpx;
    border-radius: 12rpx;
    overflow: hidden;
    box-shadow: 0 16rpx 80rpx 0 rgba(0, 0, 0, .04);
    position: relative;
  }

  .item-wrapper {
    display: flex;
    align-items: center;

    &.disable {
      .coupon-tag {
        background: linear-gradient(-113deg, #bdbdbd, #a2a1a2);
      }

      .coupon-reduce {
        color: #757575;
      }

      .state-text {
        color: #757575;
      }
    }
  }

  .coupon-tag {
    position: absolute;
    left: 0;
    top: 0;
    color: #fff;
    width: 96rpx;
    text-align: center;
    font-size: 22rpx;
    border-radius: 12rpx 0 12rpx 0;
    font-weight: 500;
    height: 34rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(to right, $main-bg, $main-bg2);
    color: $main-text;
  }

  .coupon-left {
    width: 242rpx;
    text-align: center;
    display: flex;
    flex-flow: column;
    justify-content: center;
    position: relative;

    .coupon-reduce {
      color: $main-bg;

      &-unit {
        display: inline-block;
        margin-right: -4rpx;
        font-size: 32rpx;
        font-weight: 600;
      }

      &-amount {
        display: inline-block;

        .value {
          font-size: 48rpx;
        }
      }

    }

    .coupon-hint {
      margin-top: 12rpx;
      color: #757575;
      font-size: 24rpx;
      white-space: nowrap;
      text-overflow: ellipsis;
      overflow: hidden;
    }
  }

  .coupon-content {
    flex: 1;
    padding: 32rpx 0;
    position: relative;
    display: flex;
    flex-direction: column;
    flex-wrap: nowrap;
    align-items: flex-start;
    justify-content: space-between;

    .coupon-name {
      color: #212121;
      font-size: 28rpx;
      font-weight: 500;
    }

    .coupon-middle {
      flex: 1;
      padding-top: 12rpx;
      margin-bottom: 26rpx;

      .coupon-expire {
        color: #757575;
        font-size: 24rpx;
      }
    }

    .coupon-expand {
      display: flex;
      align-items: center;
      color: #9e9e9e;
      font-size: 24rpx;

      &-arrow {
        margin-top: 4rpx;
        font-size: 24rpx;
        display: inline-block;
        vertical-align: initial;
        transform: rotate(0);
        margin-left: 8rpx;
        transition: all .15s ease-in-out;

        &.expand {
          transform: rotate(180deg);
        }
      }
    }

  }

  .coupon-right {
    padding-right: 38rpx;

    .btn-receive,
    .state-text {
      text-align: center;
      width: 100rpx;
      padding: 15rpx 0;
    }

    .btn-receive {
      font-size: 23rpx;
      line-height: 1;
      font-weight: 500;
      border-radius: 8rpx;
      cursor: pointer;
      background: linear-gradient(to right, $main-bg, $main-bg2);
      color: $main-text;
    }

    .state-text {}
  }

  .coupon-expand-rules {
    display: none;
    position: relative;

    &.expand {
      top: -30rpx;
      display: block;
    }

    &-content {
      padding: 8rpx 30rpx 8rpx 242rpx;
      font-weight: 400;
      color: #9e9e9e;
      line-height: 36rpx;

      .pre {
        font-family: auto;
        white-space: pre-wrap;
        word-wrap: break-word;
        word-break: break-all;
      }
    }
  }
</style>