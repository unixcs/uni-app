<template>
  <!-- 导航组 -->
  <view class="diy-navBar"
    :style="{ padding: `${itemStyle.paddingTop}px 0`, background: itemStyle.background, color: itemStyle.textColor }">
    <view class="data-list" :class="[`avg-sm-${itemStyle.rowsNum}`]">
      <view class="item-nav" v-for="(dataItem, index) in dataList" :key="index" @click="onLink(dataItem.link)">
        <view class="item-image">
          <image class="image" :style="{ width: `${itemStyle.imageSize * 2}rpx`, height: `${itemStyle.imageSize * 2}rpx` }" mode="scaleToFill"
            :src="dataItem.imgUrl"></image>
        </view>
        <view class="item-text oneline-hide">{{ dataItem.text }}</view>
      </view>
    </view>
  </view>
</template>

<script>
  import mixin from '../mixin'

  export default {
    name: "NavBar",
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
  .diy-navBar .data-list::after {
    clear: both;
    content: " ";
    display: table;
  }

  .diy-navBar {
    display: flex;
    flex-direction: column;

    .data-list {
      margin-bottom: -20rpx;
    }

    .item-nav {
      float: left;
      text-align: center;
      margin-bottom: 20rpx;
      font-size: 26rpx;

      .item-image {
        text-align: center;
        margin-bottom: 8rpx;

        .image {
          display: block;
          width: 88rpx;
          height: 88rpx;
          margin: 0 auto;
        }
      }
    }

    // 分列布局
    .avg-sm-3>.item-nav {
      width: 33.33333333%;
    }

    .avg-sm-4>.item-nav {
      width: 25%;
    }

    .avg-sm-5>.item-nav {
      width: 20%;
    }

  }
</style>