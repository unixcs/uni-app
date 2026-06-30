<template>
  <!-- 标题文本 -->
  <view class="diy-title" :style="wrapStyle">
    <view class="title-content">
      <!-- 标题文字 -->
      <view class="title">
        <text
          :style="titleStyle">{{ safeParams.title || '' }}</text>
      </view>
      <!-- 查看更多 -->
      <view v-if="safeMore.enable" class="more-content" :style="moreStyle" @click="onLink(safeMore.link)">
        <text class="more-text">{{ safeMore.text || '' }}</text>
        <text v-if="safeMore.enableIcon" class="more-icon">
          <text class="iconfont icon-arrow-right"></text>
        </text>
      </view>
    </view>
    <!-- 描述文字 -->
    <view class="desc-content">
      <text
        :style="descStyle">{{ safeParams.desc || '' }}</text>
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
      itemStyle: Object,
      params: Object
    },

    mixins: [mixin],
    computed: {
      safeItemStyle() {
        return this.itemStyle || {}
      },
      safeParams() {
        return this.params || {}
      },
      safeMore() {
        return this.safeParams.more || {}
      },
      wrapStyle() {
        return {
          padding: `${(Number(this.safeItemStyle.paddingY) || 0) * 2}rpx 30rpx`,
          background: this.safeItemStyle.background || 'transparent'
        }
      },
      titleStyle() {
        return {
          color: this.safeItemStyle.titleTextColor || '#323233',
          fontSize: `${(Number(this.safeParams.titleFontSize) || 14) * 2}rpx`,
          fontWeight: this.safeParams.titleFontWeight || 'normal'
        }
      },
      moreStyle() {
        return {
          color: this.safeItemStyle.moreTextColor || '#969799'
        }
      },
      descStyle() {
        return {
          color: this.safeItemStyle.descTextColor || '#969799',
          fontSize: `${(Number(this.safeParams.descFontSize) || 12) * 2}rpx`,
          fontWeight: this.safeParams.descFontWeight || 'normal'
        }
      }
    },

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
