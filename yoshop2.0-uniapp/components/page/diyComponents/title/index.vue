<template>
  <!-- 标题文本 -->
  <view class="diy-title" :style="{ padding: `${itemStyle.paddingY * 2}rpx 30rpx`, background: itemStyle.background }">
    <div class="title-content">
      <!-- 标题文字 -->
      <div class="title">
        <text
          :style="{ color: itemStyle.titleTextColor, fontSize: `${params.titleFontSize * 2}rpx`, fontWeight: params.titleFontWeight }">{{ params.title }}</text>
      </div>
      <!-- 查看更多 -->
      <div v-if="params.more.enable" class="more-content" :style="{ color: itemStyle.moreTextColor }" @click="onLink(params.more.link)">
        <text class="more-text">{{ params.more.text }}</text>
        <text v-if="params.more.enableIcon" class="more-icon">
          <text class="iconfont icon-arrow-right"></text>
        </text>
      </div>
    </div>
    <!-- 描述文字 -->
    <div class="desc-content">
      <text
        :style="{ color: itemStyle.descTextColor, fontSize: `${params.descFontSize * 2}rpx`, fontWeight: params.descFontWeight }">{{ params.desc }}</text>
    </div>
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
  .diy-title {
    padding: 10rpx 30rpx;

    .title-content {
      display: flex;
      justify-content: space-between;

      .title {
        color: #323233;
        font-size: 28rpx;
        flex: 1;
      }

      .more-content {
        color: #969799;
        font-size: 24rpx;
        margin-left: 40rpx;
      }
    }

    .desc-content {
      margin-top: 16rpx;
      color: #969799;
      font-size: 24rpx;
    }

  }
</style>