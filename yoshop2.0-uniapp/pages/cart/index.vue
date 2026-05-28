<template>
  <view class="container" :style="appThemeStyle">
    <!-- 页面顶部 -->
    <view v-if="list.length" class="head-info">
      <view class="cart-total">
        <text>共</text>
        <text class="active">{{ total }}</text>
        <text>件商品</text>
      </view>
      <view class="cart-edit" @click="handleToggleMode()">
        <view v-if="mode == 'normal'" class="normal">
          <text class="icon iconfont icon-bianji"></text>
          <text>编辑</text>
        </view>
        <view v-if="mode == 'edit'" class="edit">
          <text>完成</text>
        </view>
      </view>
    </view>
    <!-- 购物车商品列表 -->
    <view v-if="list.length" class="cart-list">
      <view class="cart-item" v-for="(item, index) in list" :key="index">
        <view class="item-radio" @click="handleCheckItem(item.id)">
          <u-checkbox :modelValue="inArray(item.id, checkedIds)" shape="circle" :activeColor="appTheme.mainBg" />
        </view>
        <view class="goods-image" @click="onTargetGoods(item.goods_id)">
          <image class="image" :src="item.goods.goods_image" mode="scaleToFill"></image>
        </view>
        <view class="item-content">
          <view class="goods-title" @click="onTargetGoods(item.goods_id)">
            <text class="twoline-hide">{{ item.goods.goods_name }}</text>
          </view>
          <view class="goods-props clearfix">
            <view class="goods-props-item" v-for="(props, idx) in item.goods.skuInfo.goods_props" :key="idx">
              <text>{{ props.value.name }}</text>
            </view>
          </view>
          <view class="item-foot">
            <view class="goods-price">
              <text class="unit">￥</text>
              <text class="value">{{ item.goods.skuInfo.goods_price }}</text>
            </view>
            <view class="stepper">
              <u-number-box :min="1" :modelValue="item.goods_num" :step="1" @change="onChangeStepper($event, item)" />
            </view>
          </view>
        </view>
      </view>
    </view>
    <!-- 购物车数据为空 -->
    <empty v-if="!list.length" :isLoading="isLoading" :custom-style="{ padding: '180rpx 50rpx' }" tips="您的购物车是空的, 快去逛逛吧">
      <template v-slot:slot>
        <view class="empty-ipt" @click="onTargetIndex()">
          <text>去逛逛</text>
        </view>
      </template>
    </empty>

    <!-- 底部操作栏 -->
    <view v-if="list.length" class="footer-fixed">
      <view class="all-radio">
        <u-checkbox :modelValue="checkedIds.length > 0 && checkedIds.length === list.length" shape="circle" :activeColor="appTheme.mainBg"
          @change="handleCheckAll()">全选</u-checkbox>
      </view>
      <view class="total-info">
        <text>合计：</text>
        <view class="goods-price">
          <text class="unit">￥</text>
          <text class="value">{{ totalPrice }}</text>
        </view>
      </view>
      <view class="cart-action">
        <view class="btn-wrapper">
          <!-- dev:下面的disabled条件使用checkedIds.join方式判断 -->
          <!-- dev:通常情况下vue项目使用checkedIds.length更合理, 但是length属性在微信小程序中不起作用 -->
          <view v-if="mode == 'normal'" class="btn-item btn-main" :class="{ disabled: checkedIds.join() == '' }" @click="handleOrder()">
            <text>去结算</text>
            <!-- <text>去结算 {{ checkedIds.length > 0 ? `(${total})` : '' }}</text> -->
          </view>
          <view v-if="mode == 'edit'" class="btn-item btn-main" :class="{ disabled: !checkedIds.length }" @click="handleDelete()">
            <text>删除</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
  import Empty from '@/components/empty'
  import { inArray, arrayIntersect, debounce } from '@/utils/util'
  import { checkLogin, setCartTotalNum, setCartTabBadge } from '@/core/app'
  import * as CartApi from '@/api/cart'

  const CartIdsIndex = 'CartIds'

  export default {
    components: {
      Empty
    },
    data() {
      return {
        inArray,
        // 正在加载
        isLoading: true,
        // 当前模式: normal正常 edit编辑
        mode: 'normal',
        // 购物车商品列表
        list: [],
        // 购物车商品总数量
        total: null,
        // 选中的商品ID记录
        checkedIds: [],
        // 选中的商品总金额
        totalPrice: '0.00'
      }
    },
    watch: {
      // 监听选中的商品
      checkedIds: {
        handler(val) {
          // 计算合计金额
          this.onCalcTotalPrice()
          // 记录到缓存中
          uni.setStorageSync(CartIdsIndex, val)
        },
        deep: true,
        immediate: false
      },
      // 监听购物车商品总数量
      total(val) {
        // 缓存并设置角标
        setCartTotalNum(val)
        setCartTabBadge()
      }
    },

    /**
     * 生命周期函数--监听页面显示
     */
    onShow() {
      // 获取缓存中的选中记录
      this.checkedIds = uni.getStorageSync(CartIdsIndex)
      // 获取购物车商品列表
      checkLogin() ? this.getCartList() : this.isLoading = false
    },

    methods: {

      // 计算合计金额 (根据选中的商品)
      onCalcTotalPrice() {
        const app = this
        // 选中的商品记录
        const checkedList = app.list.filter(item => inArray(item.id, app.checkedIds))
        // 计算总金额
        let tempPrice = 0;
        checkedList.forEach(item => {
          // 商品单价, 为了方便计算先转换单位为分 (整数)
          const unitPrice = item.goods.skuInfo.goods_price * 100
          tempPrice += unitPrice * item.goods_num
        })
        app.totalPrice = (tempPrice / 100).toFixed(2)
      },

      // 获取购物车商品列表
      getCartList() {
        const app = this
        app.isLoading = true
        CartApi.list()
          .then(result => {
            app.list = result.data.list
            app.total = result.data.cartTotal
            // 清除checkedIds中无效的ID
            app.onClearInvalidId()
          })
          .finally(() => app.isLoading = false)
      },

      // 清除checkedIds中无效的ID
      onClearInvalidId() {
        const app = this
        const listIds = app.list.map(item => item.id)
        app.checkedIds = arrayIntersect(listIds, app.checkedIds)
      },

      // 切换当前模式
      handleToggleMode() {
        this.mode = this.mode == 'normal' ? 'edit' : 'normal'
      },

      // 监听步进器更改事件
      onChangeStepper({ value }, item) {
        // 这里是组织首次启动时的执行
        if (item.goods_num == value) return
        // 记录一个节流函数句柄
        if (!item.debounceHandle) {
          item.oldValue = item.goods_num
          item.debounceHandle = debounce(this.onUpdateCartNum, 500)
        }
        // 更新商品数量
        item.goods_num = value
        // 提交更新购物车数量 (节流)
        item.debounceHandle(item, item.oldValue, value)
      },

      // 提交更新购物车数量
      onUpdateCartNum(item, oldValue, newValue) {
        const app = this
        CartApi.update(item.goods_id, item.goods_sku_id, newValue)
          .then(result => {
            // 更新商品数量
            app.total = result.data.cartTotal
            // 重新计算合计金额
            app.onCalcTotalPrice()
            // 清除节流函数句柄
            item.debounceHandle = null
          })
          .catch(err => {
            // 还原商品数量
            item.goods_num = oldValue
            setTimeout(() => app.$toast(err.errMsg), 10)
          })
      },

      // 跳转到商品详情页
      onTargetGoods(goodsId) {
        this.$navTo('pages/goods/detail', { goodsId })
      },

      // 点击去逛逛按钮, 跳转到首页
      onTargetIndex() {
        this.$navTo('pages/index/index')
      },

      // 选中商品
      handleCheckItem(cartId) {
        const { checkedIds } = this
        const index = checkedIds.findIndex(id => id === cartId)
        index < 0 ? checkedIds.push(cartId) : checkedIds.splice(index, 1)
      },

      // 全选事件
      handleCheckAll() {
        const { checkedIds, list } = this
        this.checkedIds = checkedIds.length === list.length ? [] : list.map(item => item.id)
      },

      // 结算选中的商品
      handleOrder() {
        const app = this
        if (app.checkedIds.length) {
          const cartIds = app.checkedIds.join()
          app.$navTo('pages/checkout/index', { mode: 'cart', cartIds })
        }
      },

      // 删除选中的商品弹窗事件
      handleDelete() {
        const app = this
        if (!app.checkedIds.length) {
          return false
        }
        uni.showModal({
          title: '友情提示',
          content: '您确定要删除该商品吗？',
          showCancel: true,
          success({ confirm }) {
            // 确认删除
            confirm && app.onClearCart()
          }
        })
      },

      // 确认删除商品
      onClearCart() {
        const app = this
        CartApi.clear(app.checkedIds)
          .then(result => {
            app.getCartList()
            app.handleToggleMode()
          })
      }

    }
  }
</script>

<style>
  page {
    background: #f5f5f5;
  }
</style>
<style lang="scss" scoped>
  .container {
    padding-bottom: 120rpx;
  }

  // 页面顶部
  .head-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4rpx 30rpx;
    // background-color: #fff;
    height: 80rpx;

    .cart-total {
      font-size: 28rpx;
      color: #333;

      .active {
        color: $main-bg;
        margin: 0 2rpx;
      }
    }

    .cart-edit {
      padding-left: 20rpx;

      .icon {
        margin-right: 12rpx;
      }

      .edit {
        color: $main-bg;
      }
    }

  }

  // 购物车列表
  .cart-list {
    padding: 0 16rpx 0 16rpx;
  }

  .cart-item {
    background: #fff;
    border-radius: 12rpx;
    display: flex;
    align-items: center;
    padding: 30rpx 16rpx;
    margin-bottom: 24rpx;

    .item-radio {
      width: 56rpx;
      height: 80rpx;
      margin-right: 10rpx;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .goods-image {
      width: 200rpx;
      height: 200rpx;

      .image {
        display: block;
        width: 100%;
        height: 100%;
        border-radius: 8rpx;
      }
    }

    .item-content {
      flex: 1;
      padding-left: 24rpx;

      .goods-title {
        font-size: 28rpx;
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

      .item-foot {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 20rpx;

        .goods-price {
          vertical-align: bottom;
          color: $main-bg;

          .unit {
            font-size: 24rpx;
          }

          .value {
            font-size: 32rpx;
          }
        }
      }

    }
  }

  // 空数据按钮
  .empty-ipt {
    margin: 0 auto;
    width: 250rpx;
    height: 70rpx;
    font-size: 32rpx;
    text-align: center;
    color: #fff;
    border-radius: 50rpx;
    background: linear-gradient(to right, $main-bg, $main-bg2);
    color: $main-text;
    display: flex;
    justify-content: center;
    align-items: center;
  }

  // 底部操作栏
  .footer-fixed {
    display: flex;
    align-items: center;
    height: 96rpx;
    background: #fff;
    padding: 0 30rpx;
    position: fixed;
    bottom: var(--window-bottom);
    left: var(--window-left);
    right: var(--window-right);
    z-index: 11;

    .all-radio {
      width: 140rpx;
      display: flex;
      align-items: center;

      .radio {
        margin-bottom: -4rpx;
        transform: scale(0.76)
      }
    }

    .total-info {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      padding-right: 30rpx;

      .goods-price {
        vertical-align: bottom;
        color: $main-bg;

        .unit {
          font-size: 24rpx;
        }

        .value {
          font-size: 32rpx;
        }
      }
    }

    .cart-action {
      width: 200rpx;

      .btn-wrapper {
        height: 100%;
        display: flex;
        align-items: center;
      }

      .btn-item {
        flex: 1;
        font-size: 28rpx;
        height: 72rpx;
        color: #fff;
        border-radius: 50rpx;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      // 去结算按钮
      .btn-main {
        background: linear-gradient(to right, $main-bg, $main-bg2);
        color: $main-text;

        // 禁用按钮
        &.disabled {
          opacity: 0.6;
        }
      }

    }

  }
</style>