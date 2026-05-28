<template>
  <!-- 单图组 -->
  <view class="diy-imageSingle"
    :style="{ padding: `${itemStyle.paddingTop * 2}rpx ${itemStyle.paddingLeft * 2}rpx`, background: itemStyle.background }">
    <view class="item-image" v-for="(dataItem, index) in dataList" :key="index"
      :style="{ marginBottom: `${itemStyle.itemMargin * 2}rpx`, borderRadius: `${itemStyle.borderRadius * 2}rpx` }">
      <view class="nav-to" @click="onLink(dataItem.link)">
        <image class="image" :src="dataItem.imgUrl" mode="widthFix"></image>
      </view>
    </view>
  </view>
</template>

<script>
  import mixin from '../mixin'

  export default {
    props: {
      itemIndex: String,
      itemStyle: Object,
      params: Object,
      dataList: Array
    },
    mixins: [mixin],
    methods: {

    }
  }
</script>

<style lang="scss" scoped>
  .item-image {
    overflow: hidden;

    &:last-child {
      margin-bottom: 0 !important;
    }

    .image {
      display: block;
      width: 100%;
    }
  }
</style>