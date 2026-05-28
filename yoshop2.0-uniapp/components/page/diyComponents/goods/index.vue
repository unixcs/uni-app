<template>
  <!-- 商品组 -->
  <scroll-view class="scroll-view" :scroll-x="itemStyle.display === 'slide'" :style="{ background: itemStyle.background }">
    <view class="diy-goods">
      <view class="goods-list" :class="[`display-${itemStyle.display}`, `column-${itemStyle.column}`]"
        :style="{ padding: `${itemStyle.paddingY * 2}rpx ${itemStyle.paddingX * 2}rpx` }">
        <view class="goods-item" v-for="(dataItm, dataIdx) in dataList" :key="dataIdx" :class="[`display-${itemStyle.cardType}`]"
          :style="{ marginBottom: `${itemStyle.itemMargin * 2}rpx`, borderRadius: `${itemStyle.borderRadius * 2}rpx` }"
          @click="handleGoodsItem(dataItm.goods_id)">
          <!-- 单列商品 -->
          <template v-if="itemStyle.column === 1">
            <view class="flex">
              <view class="goods-item-left">
                <image class="image" :src="dataItm.goods_image"></image>
              </view>
              <view class="goods-item-right">
                <view class="goods-info">
                  <view v-if="inArray('goodsName', itemStyle.show)" class="goods-name"
                    :class="[ itemStyle.goodsNameRows == 'two' ? 'twoline-hide' : 'oneline-hide', `row-${itemStyle.goodsNameRows}` ]">
                    {{ dataItm.goods_name }}
                  </view>
                  <view v-if="inArray('sellingPoint', itemStyle.show)" class="goods-selling">
                    <text class="selling oneline-hide" :style="{ color: itemStyle.sellingColor }">{{ dataItm.selling_point }}</text>
                  </view>
                  <view v-if="inArray('goodsSales', itemStyle.show)" class="goods-sales oneline-hide">
                    <text class="sales">已售{{ dataItm.goods_sales }}</text>
                    <!-- <text class="line">|</text>
                                      <text>惊艳度100%</text>-->
                  </view>
                  <view class="footer">
                    <view class="goods-price oneline-hide" :style="{ color: itemStyle.priceColor }">
                      <template v-if="inArray('goodsPrice', itemStyle.show)">
                        <text class="unit">￥</text>
                        <text class="value">{{ dataItm.goods_price_min }}</text>
                        <!-- <text class="unit2">到手价</text> -->
                      </template>
                      <text v-if="inArray('linePrice', itemStyle.show) && dataItm.line_price_min > 0" class="line-price">
                        <text class="unit">￥</text>
                        <text class="value">{{ dataItm.line_price_min }}</text>
                      </text>
                    </view>
                    <view v-show="inArray('cartBtn', itemStyle.show) && itemStyle.column < 3" class="action">
                      <view class="btn-cart" :style="{ color: itemStyle.btnCartColor }" @click.stop="handleAddCart(dataItm)">
                        <text class="cart-icon iconfont" :class="[`icon-jiagou${itemStyle.btnCartStyle}`]"></text>
                      </view>
                    </view>
                  </view>
                </view>
              </view>
            </view>
          </template>
          <!-- 两列/三列 -->
          <template v-else>
            <view class="goods-image">
              <image class="image" mode="aspectFill" :src="dataItm.goods_image"></image>
            </view>
            <view class="goods-info">
              <view v-if="inArray('goodsName', itemStyle.show)" class="goods-name"
                :class="[ itemStyle.goodsNameRows == 'two' ? 'twoline-hide' : 'oneline-hide', `row-${itemStyle.goodsNameRows}` ]">
                {{ dataItm.goods_name }}
              </view>
              <view v-if="inArray('sellingPoint', itemStyle.show)" class="goods-selling">
                <text class="selling oneline-hide" :style="{ color: itemStyle.sellingColor }">{{ dataItm.selling_point }}</text>
              </view>
              <view v-if="inArray('goodsSales', itemStyle.show)" class="goods-sales oneline-hide">
                <text class="sales">已售{{ dataItm.goods_sales }}</text>
                <!-- <text class="line">|</text>
                            <text>惊艳度100%</text>-->
              </view>
              <view class="footer">
                <view v-if="inArray('goodsPrice', itemStyle.show)" class="goods-price oneline-hide"
                  :style="{ color: itemStyle.priceColor }">
                  <text class="unit">￥</text>
                  <text class="value">{{ dataItm.goods_price_min }}</text>
                  <!-- <text class="unit2">到手价</text> -->
                  <text v-if="inArray('linePrice', itemStyle.show) && dataItm.line_price_min > 0" class="line-price">
                    <text class="unit">￥</text>
                    <text class="value">{{ dataItm.line_price_min }}</text>
                  </text>
                </view>
                <view v-show="inArray('cartBtn', itemStyle.show) && itemStyle.column < 3" class="action">
                  <view class="btn-cart" :style="{ color: itemStyle.btnCartColor }" @click.stop="handleAddCart(dataItm)">
                    <text class="cart-icon iconfont" :class="[`icon-jiagou${itemStyle.btnCartStyle}`]"></text>
                  </view>
                </view>
              </view>
            </view>
          </template>
        </view>
      </view>
    </view>
    <!-- 加入购物车组件 -->
    <AddCartPopup ref="AddCartPopup" @addCart="onAddCart" />
  </scroll-view>
</template>

<script>
  import { inArray } from '@/utils/util'
  import { setCartTabBadge } from '@/core/app'
  import AddCartPopup from '@/components/add-cart-popup'

  export default {
    components: {
      AddCartPopup
    },
    props: {
      itemIndex: String,
      itemStyle: Object,
      params: Object,
      dataList: Array
    },
    data() {
      return { inArray }
    },
    methods: {

      // 跳转商品详情页
      handleGoodsItem(goodsId) {
        if (goodsId !== undefined) {
          this.$navTo(`pages/goods/detail`, { goodsId })
        }
      },

      // 点击加入购物车
      handleAddCart(item) {
        this.$refs.AddCartPopup.handle(item)
      },

      // 更新购物车角标
      onAddCart(total) {
        setCartTabBadge()
      }

    }

  }
</script>

<style lang="scss" scoped>
  .diy-goods {
    // 解决 margin-bottom: -10px 导致的容器异常
    display: flex;
    flex-direction: column;
    // overflow: hidden; // 会导致横向无法滑动

    .goods-list {
      margin-bottom: -10px;

      // 横向滑动
      &.display-slide {
        display: flex;
        width: max-content;

        .goods-item {
          flex-shrink: 1;
          margin-right: 20rpx !important;

          &:last-child {
            margin-right: 0 !important;
          }
        }

        // 双列
        &.column-2 {
          .goods-item {
            width: 160px;
          }
        }

        // 3列
        &.column-3 {
          .goods-item {
            width: 220px;
          }
        }
      }

      // 列表平铺
      &.display-list {
        display: flex;
        flex-wrap: wrap;
      }

      // 单列
      &.column-1 {
        margin-bottom: 0;

        .goods-item {
          margin-bottom: 12rpx;
          padding: 20rpx;
          box-sizing: border-box;
          background: #fff;
          flex-basis: 100%;
          margin-right: 0;

          &:last-child {
            margin-bottom: 0 !important;
          }

          .goods-info {
            padding: 0;

            .footer {
              position: absolute;
              bottom: 16rpx;
              width: 100%;
            }
          }

        }

        // 商品图片
        .goods-item-left {
          background: #fff;
          margin-right: 20rpx;

          .image {
            display: block;
            width: 240rpx;
            height: 240rpx;
          }
        }

        .goods-item-right {
          position: relative;
          flex: 1;
          width: 0; // 解决文字超出无法隐藏
        }

      }

      // 2列
      &.column-2 {
        .goods-item {
          flex-basis: 48.6%;

          &:nth-child(2n) {
            margin-right: 0;
          }
        }
      }

      // 3列
      &.column-3 {
        .goods-item {
          flex-basis: 31.46666%;

          &:nth-child(3n) {
            margin-right: 0;
          }
        }

        .goods-name {
          font-size: 26rpx !important;
        }
      }

      // 商品内容
      .goods-item {
        flex-shrink: 0;
        background-color: #fff;
        flex: 0 1 48.6%;
        margin-right: 2.8%;
        border-radius: 16rpx;
        overflow: hidden;
        margin-bottom: 20rpx;

        &.display-card {
          box-shadow: 0 4rpx 10rpx rgba(0, 0, 0, 0.07);
        }

        .goods-image {
          position: relative;
          width: 100%;
          height: 0;
          padding-bottom: 100%;
          overflow: hidden;

          &:after {
            content: '';
            display: block;
            margin-top: 100%;
          }

          .image {
            position: absolute;
            top: 0;
            left: 0;
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
          }
        }

        .goods-info {
          padding: 20rpx;

          .goods-name {
            font-size: 27rpx;
            color: #000;
            margin-bottom: 8rpx;
            // line-height: 1.3; // 用户端

            &.row-two {
              // min-height: 68rpx; // 用户端
            }
          }

          .goods-selling {
            display: flex; // 解决文字超出无法隐藏
            font-size: 24rpx;
            margin-bottom: 8rpx;
            min-height: 38rpx;
          }

          .goods-sales {
            font-size: 24rpx;
            color: #959595;
            margin-bottom: 8rpx;

            .line {
              margin: 0 8rpx;
            }
          }

        }

        .footer {
          display: flex;
          justify-content: space-between;
          align-items: center;

          .goods-price {
            position: relative;
            color: #ff1051;

            .unit {
              font-size: 20rpx;
            }

            .value {
              font-size: 30rpx;
            }

            .unit2 {
              margin-left: 4rpx;
              font-size: 22rpx;
            }

            .line-price {
              margin-left: 6rpx;
              color: #959595;

              .unit {
                text-decoration: line-through;
                font-size: 22rpx;
              }

              .value {
                text-decoration: line-through;
                font-size: 22rpx;
              }
            }

          }

          .action {

            .btn-cart {
              text-align: center;

              .cart-icon {
                font-size: 36rpx;
              }
            }

          }

        }

      }
    }
  }
</style>