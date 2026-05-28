<template>
  <!-- 关注公众号 -->
  <!-- #ifdef MP-WEIXIN -->
  <view class="diy-officialAccount">
    <official-account></official-account>
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
      itemIndex: String
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