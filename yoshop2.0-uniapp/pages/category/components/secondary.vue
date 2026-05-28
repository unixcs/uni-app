<template>
  <view class="container" :style="appThemeStyle">
    <!-- 一级分类 -->
    <scroll-view class="cate-left" :scroll-y="true" @touchmove.stop.prevent>
      <text class="type-nav" :class="{ selected: curIndex == index }" v-for="(item, index) in list" :key="index"
        @click="handleSelectNav(index)">{{ item.name }}</text>
    </scroll-view>
    <view class="cate-content">
      <!-- 二级分类列表 -->
      <view class="category-list clearfix">
        <view class="category-item" v-for="(item, index) in list[curIndex].children" :key="index" @click="handleGoods(item.category_id)">
          <view v-if="item.image" class="item-image">
            <image class="image" mode="scaleToFill" :src="item.image.preview_url"></image>
          </view>
          <view class="item-name">
            <text class="oneline-hide">{{ item.name }}</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
  import * as GoodsApi from '@/api/goods'

  export default {
    props: {
      // 分类列表
      list: {
        type: Array,
        default: []
      }
    },
    data() {
      return {
        // 一级分类：指针
        curIndex: 0
      }
    },
    methods: {

      // 一级分类：选中分类
      handleSelectNav(index) {
        this.curIndex = index
      },

      // 跳转至商品列表页
      handleGoods(categoryId) {
        this.$navTo('pages/goods/list', { categoryId })
      }

    }
  }
</script>

<style lang="scss" scoped>
  .container {
    padding-left: 175rpx;
  }

  // 分类内容
  .cate-content {
    z-index: 1;
    background: #fff;
    padding-top: 90rpx;
    min-height: 300rpx;
  }

  // 一级分类+二级分类 20
  .cate-left {
    position: fixed;
    top: calc(88rpx + var(--window-top));
    left: var(--window-left);
    bottom: var(--window-bottom);
    width: 175rpx;
    height: calc(100% - var(--window-top) - var(--window-bottom) - 90rpx) !important;
    background: #f8f8f8;
    color: #444;
  }

  // 左侧一级分类
  .type-nav {
    position: relative;
    height: 90rpx;
    z-index: 10;
    display: block;
    font-size: 26rpx;
    display: flex;
    justify-content: center;
    align-items: center;

    &.selected {
      background: #fff;
      border-right: none;
      font-size: 28rpx;
      color: $main-bg
    }
  }

  // 分类列表
  .category-list {
    padding: 30rpx 20rpx;
  }

  .category-item {
    float: left;
    width: 33.33333%;
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 40rpx;

    .item-image {
      .image {
        display: block;
        width: 146rpx;
        height: 146rpx;
      }
    }

    .item-name {
      margin-top: 10rpx;
      text-align: center;
      font-size: 26rpx;
    }
  }
</style>