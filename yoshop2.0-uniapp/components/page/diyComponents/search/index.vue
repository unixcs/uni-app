<template>
  <!-- 搜索框 -->
  <view class="diy-search" :class="{ sticky: params.sticky }"
    :style="{ background: itemStyle.background, padding: `${itemStyle.paddingY * 2}rpx ${itemStyle.paddingX * 2}rpx` }">
    <view class="inner" :class="itemStyle.searchStyle" @click="onTargetSearch">
      <view class="search-input"
        :style="{ textAlign: itemStyle.textAlign, background: itemStyle.searchBg, color: itemStyle.searchFontColor }">
        <text class="search-icon iconfont icon-search"></text>
        <text> {{ params.placeholder }}</text>
      </view>
    </view>
  </view>
</template>

<script>
  export default {

    /**
     * 组件的属性列表
     * 用于组件自定义设置
     */
    props: {
      itemIndex: String,
      itemStyle: Object,
      params: Object
    },

    /**
     * 组件的方法列表
     * 更新属性和数据的方法与更新页面数据的方法类似
     */
    methods: {

      /**
       * 跳转到搜索页面
       */
      onTargetSearch() {
        this.$navTo('pages/search/index')
      }

    }

  }
</script>

<style lang="scss" scoped>
  .diy-search {
    background: #f1f1f2;
    padding: 20rpx 20rpx;

    &.sticky {
      position: sticky;
      top: var(--window-top);
      z-index: 100;
    }
  }

  .inner {
    height: 60rpx;
    background: #fff;
    overflow: hidden;

    &.radius {
      border-radius: 10rpx;
    }

    &.round {
      border-radius: 60rpx;
    }

    .search-input {
      height: 60rpx;
      //width: 100%;
      line-height: 60rpx;
      color: #999999;
      padding: 0 24rpx;
      font-size: 26rpx;

      .search-icon {
        margin-right: 12rpx;
      }
    }
  }
</style>