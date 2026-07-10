<template>
  <!-- 用户隐私保护弹窗 -->
  <!-- #ifdef MP-WEIXIN -->
  <view v-if="storeInfo" class="privacy-popup">
    <u-popup v-model="showPrivacy" mode="bottom" border-radius="20" :safe-area-inset-bottom="true" :mask-close-able="false"
      :mask-custom-style="{ background: 'rgba(0, 0, 0, 0.7)' }">
      <view class="privacy-container">
        <view class="title">用户隐私保护提示</view>
        <view class="content">
          <text>感谢您使用{{ storeInfo.store_name }}，同意并继续表示您已阅读并同意</text>
          <text class="col-m" @click="handlePrivacyContract()">《隐私政策》</text>
          <text>，否则将无法正常使用相关功能。</text>
        </view>
        <view class="actions">
          <view class="btn-item btn-grey" @click="handleDisagree()">
            <text>不同意</text>
          </view>
          <button class="btn-item btn-main btn-normal" open-type="agreePrivacyAuthorization"
            @agreeprivacyauthorization="handleAgreePrivacyAuthorization()">
            <text>同意并继续</text>
          </button>
        </view>
      </view>
    </u-popup>
  </view>
  <!-- #endif -->
</template>

<script>
  import StoreModel from '@/common/model/Store'

  export default {
    emits: ['end'],
    props: {
      // 弹出隐私窗口时是否隐藏tabbar
      hideTabBar: {
        type: Boolean,
        default: false
      }
    },

    data() {
      return {
        // 隐私协议弹窗
        showPrivacy: false,
        // 商城基本信息
        storeInfo: undefined,
      }
    },

    async created() {
      // 获取商城基本信息
      try {
        await this.getStoreInfo()
        if (!this.storeInfo) {
          this.$emit('end')
          return
        }
        // #ifdef MP-WEIXIN
        // 弹出隐私协议 (微信小程序端)
        this.needAuthorization()
        // #endif
      } catch (err) {
        this.$emit('end')
      }
    },

    methods: {

      // 获取商城基本信息
      async getStoreInfo() {
        await StoreModel.storeInfo()
          .then(storeInfo => this.storeInfo = storeInfo)
          .catch(() => {
            this.storeInfo = undefined
          })
      },

      // 弹出隐私协议 (微信小程序端)
      needAuthorization() {
        if (typeof uni.getPrivacySetting !== 'function') {
          this.$emit('end')
          return
        }
        const app = this
        uni.getPrivacySetting({
          success({ needAuthorization }) {
            // 需要弹出隐私协议
            if (needAuthorization) {
              app.showPrivacy = true
              app.hideTabBar && uni.hideTabBar()
            } else {
              app.$emit('end')
            }
          },
          fail() {
            app.$emit('end')
          }
        })
      },

      // 查看隐私协议内容
      handlePrivacyContract() {
        uni.openPrivacyContract()
      },

      // 用户同意隐私协议事件回调
      handleAgreePrivacyAuthorization() {
        // 用户点击了同意，才可以调用隐私接口和组件，例如：
        // wx.getUserProfile()
        // wx.chooseImage()
        // wx.saveImageToPhotosAlbum()
        // wx.setClipboardData()

        this.hideTabBar && uni.showTabBar()
        this.showPrivacy = false
        setTimeout(() => {
          this.$emit('end')
        }, 180)
      },

      // 用户不同意隐私协议
      handleDisagree() {
        this.$toast('很抱歉，请先同意后可继续使用~', 2000)
      },

    }
  }
</script>

<style lang="scss" scoped>
  .privacy-container {
    padding: 40rpx 60rpx;

    .title {
      text-align: center;
      margin-bottom: 40rpx;
      font-size: 32rpx;
      font-weight: bold;
    }

    .content {
      margin-bottom: 50rpx;
      font-size: 28rpx;
      line-height: 50rpx;
    }

    .actions {
      display: flex;
      justify-content: space-between;
      padding: 0 20rpx;

      .btn-item {
        width: 280rpx;
        height: 80rpx;
        line-height: 80rpx;
        border-radius: 20rpx;
        text-align: center;

        &.btn-grey {
          background: #f8f8f8;
          color: #000;
        }

        &.btn-main {
          background: linear-gradient(to right, $main-bg, $main-bg2);
          color: $main-text;
        }
      }
    }
  }
</style>
