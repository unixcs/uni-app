<template>
  <!-- 热区 -->
  <view class="diy-hotZone"
    :style="{ padding: `${itemStyle.paddingTop * 2}rpx ${itemStyle.paddingLeft * 2}rpx`, background: itemStyle.background }">
    <view class="bg-image" :style="{ borderRadius: `${itemStyle.borderRadius * 2}rpx` }">
      <image class="image" :src="data.imgUrl" mode="widthFix"></image>
    </view>
    <view class="zone-item" v-for="(item, index) in data.maps" :key="index"
      :style="{ width: `${item.width}rpx`, height: `${item.height}rpx`, left: `${item.left}rpx`, top: `${item.top}rpx` }"
      @click="onLink(item.link)">
    </view>
  </view>
</template>

<script>
  import mixin from '../mixin'

  export default {

    /**
     * 组件的属性列表
     * 用于组件自定义设置
     */
    props: {
      itemIndex: String,
      itemStyle: Object,
      params: Object,
      data: Object
    },

    mixins: [mixin],

    /**
     * 组件的方法列表
     * 更新属性和数据的方法与更新页面数据的方法类似
     */
    methods: {

    }

  }
</script>

<style lang="scss" scoped>
  .diy-hotZone {
    position: relative;
    overflow: hidden;
  }

  .bg-image {
    overflow: hidden;
    z-index: 1;

    .image {
      display: block;
      width: 100%;
    }
  }

  .zone-item {
    position: absolute;
    z-index: 2;
  }
</style>