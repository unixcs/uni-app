<template>
  <!-- 网站备案号 -->
  <!-- #ifdef H5 -->
  <view class="diy-ICPLicense"
    :style="{ padding: `${itemStyle.paddingTop * 2}rpx ${itemStyle.paddingLeft * 2}rpx`, background: itemStyle.background }">
    <view class="line" :style="{ textAlign: itemStyle.textAlign }">
      <text :style="{ fontSize: `${itemStyle.fontSize * 2}rpx`, color: itemStyle.textColor }" target="_blank"
        @click="onLink({ type: 'URL', param: { url: params.link } })">{{ params.text }}</text>
    </view>
  </view>
  <!-- #endif -->
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
      params: Object,
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