<template>
  <view class="diy-123123">

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
      dataList: Array
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
