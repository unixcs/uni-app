<template>
  <!-- 店铺公告 -->
  <view class="diy-notice" :style="{ padding: `${itemStyle.paddingTop * 2}rpx 0` }"
    @click="onLink(params.link)">
    <u-notice-bar padding="12rpx 24rpx" :volume-icon="params.showIcon" :autoplay="params.scrollable"
      :bg-color="itemStyle.background" :color="itemStyle.textColor" :list="[params.text]" :font-size="itemStyle.fontSize * 2"></u-notice-bar>
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
      itemStyle: Object,
      params: Object
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

</style>
