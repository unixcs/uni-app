<template>
  <view class="first-login-popup">
    <u-popup
      v-model="visible"
      mode="center"
      border-radius="24"
      :mask-close-able="true"
      :safe-area-inset-bottom="true"
      :mask-custom-style="{ background: 'rgba(0, 0, 0, 0.7)' }"
      @close="handleClose"
    >
      <view class="popup-card" @click="handleClose">
        <view class="popup-title">温馨提示</view>
        <view class="popup-body">{{ body }}</view>
        <view class="popup-tip">点击任意处关闭</view>
      </view>
    </u-popup>
  </view>
</template>

<script>
  export default {
    emits: ['close'],
    props: {
      body: {
        type: String,
        default: ''
      },
      hideTabBar: {
        type: Boolean,
        default: false
      }
    },
    data() {
      return {
        visible: true,
        tabBarHidden: false,
        closed: false
      }
    },
    mounted() {
      this.syncTabBar(true)
    },
    beforeDestroy() {
      this.syncTabBar(false)
    },
    beforeUnmount() {
      this.syncTabBar(false)
    },
    methods: {
      syncTabBar(shouldHide) {
        if (!this.hideTabBar) {
          return
        }
        if (shouldHide && !this.tabBarHidden) {
          uni.hideTabBar()
          this.tabBarHidden = true
          return
        }
        if (!shouldHide && this.tabBarHidden) {
          uni.showTabBar()
          this.tabBarHidden = false
        }
      },
      handleClose() {
        if (this.closed) {
          return
        }
        this.closed = true
        this.visible = false
        this.syncTabBar(false)
        this.$emit('close')
      }
    }
  }
</script>

<style lang="scss" scoped>
  .popup-card {
    width: 560rpx;
    padding: 40rpx 36rpx 32rpx;
    background: #fff;
    border-radius: 24rpx;
    text-align: center;
  }

  .popup-title {
    font-size: 34rpx;
    font-weight: 600;
    color: #111827;
  }

  .popup-body {
    margin-top: 24rpx;
    font-size: 28rpx;
    line-height: 1.7;
    color: #374151;
    white-space: pre-wrap;
    word-break: break-all;
    text-align: left;
  }

  .popup-tip {
    margin-top: 28rpx;
    font-size: 24rpx;
    color: #9ca3af;
  }
</style>
