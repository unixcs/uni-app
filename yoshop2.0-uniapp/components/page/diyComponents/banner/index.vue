<template>
  <view class="diy-banner"
    :style="{ padding: `${itemStyle.paddingTop * 2}rpx ${itemStyle.paddingLeft * 2}rpx`, background: itemStyle.background, height: `${imgHeights[swiperIndex]}px` }">
    <!-- 图片轮播 -->
    <swiper :autoplay="autoplay" class="swiper-box" :duration="duration" :circular="true" :interval="itemStyle.interval * 1000"
      @change="onChangeItem" :style="{ borderRadius: `${itemStyle.borderRadius * 2}rpx` }">
      <swiper-item class="swiper-item" v-for="(dataItem, index) in dataList" :key="index">
        <image mode="widthFix" class="slide-image" :src="dataItem.imgUrl" @click="onLink(dataItem.link)" @load="onLoadImage" />
      </swiper-item>
    </swiper>
    <!-- 指示点 -->
    <view class="indicator-dots" :class="itemStyle.btnShape"
      :style="{ '--padding-top': `${itemStyle.paddingTop > 0 ? itemStyle.paddingTop * 2 : 1 }rpx` }">
      <view class="dots-item" :class="{ active: swiperIndex == index }" :style="{ backgroundColor: itemStyle.btnColor }"
        v-for="(dataItem, index) in dataList" :key="index"></view>
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
    data() {
      return {
        windowWidth: 750,
        indicatorDots: false, // 是否显示面板指示点
        autoplay: true, // 是否自动切换
        duration: 800, // 滑动动画时长
        imgHeights: [], // 每张图片对应的容器高度
        swiperIndex: 0 // 当前banne所在滑块指针
      }
    },
    created() {
      const { windowWidth } = uni.getWindowInfo()
      this.windowWidth = windowWidth > 750 ? 750 : windowWidth
      // #ifdef H5
      // 此处取决于pages.json中的maxWidth
      this.windowWidth = windowWidth > 450 ? 450 : windowWidth
      // #endif
    },
    methods: {
      // 计算图片高度
      onLoadImage({ detail }) {
        const app = this
        // 图片的宽高比
        const ratio = detail.width / detail.height
        // 计算容器高度值
        const viewHeight = (app.windowWidth - (app.itemStyle.paddingLeft * 2)) / ratio
        // 每张图片对应的容器高度
        app.imgHeights.push(viewHeight + (app.itemStyle.paddingTop * 2))
      },

      // 记录当前指针
      onChangeItem({ detail }) {
        this.swiperIndex = detail.current
      }
    }
  }
</script>

<style lang="scss" scoped>
  .diy-banner {
    position: relative;
  }

  // swiper组件
  .swiper-box {
    height: 100%;
    overflow: hidden;

    .swiper-item {}

    .slide-image {
      width: 100%;
      height: 100%;
      margin: 0 auto;
      display: block;
    }
  }

  // 指示点
  .indicator-dots {
    --padding-top: 0;
    height: 28rpx;
    padding: 0 20rpx;
    position: absolute;
    left: 0;
    right: 0;
    bottom: calc(var(--padding-top) + 20rpx);
    opacity: 0.8;
    display: flex;
    justify-content: center;
    align-items: center;

    .dots-item {
      margin-right: 8rpx;
      width: 16rpx;
      height: 16rpx;
      background-color: #fff;

      &:last-child {
        margin-right: 0;
      }

      &.active {
        background-color: #313131 !important;
      }
    }

    // 长方形
    &.rectangle .dots-item {
      width: 32rpx;
      height: 16rpx;
    }

    // 正方形
    &.square .dots-item {}

    // 圆形
    &.round .dots-item {
      border-radius: 20rpx;
    }

  }
</style>