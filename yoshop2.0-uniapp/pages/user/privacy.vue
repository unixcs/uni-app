<template>
  <view class="container">
    <view v-if="isLoading" class="state state-loading">隐私协议加载中...</view>
    <view v-else class="content-card">
      <view class="page-title">隐私协议</view>
      <view v-if="errorText" class="state state-error">{{ errorText }}</view>
      <view v-else-if="content" class="page-content">
        <mp-html :content="content" />
      </view>
      <view v-else class="state state-empty">当前暂未配置隐私协议内容</view>
    </view>
  </view>
</template>

<script>
  import * as SettingApi from '@/api/setting'

  export default {
    data() {
      return {
        isLoading: true,
        content: '',
        errorText: ''
      }
    },
    onLoad() {
      this.getPrivacyAgreement()
    },
    methods: {
      getPrivacyAgreement() {
        this.isLoading = true
        this.errorText = ''
        SettingApi.privacyAgreement({}, { load: true })
          .then(result => {
            this.content = result.data && result.data.content ? result.data.content : ''
          })
          .catch(() => {
            this.content = ''
            this.errorText = '隐私协议加载失败，请稍后重试'
          })
          .finally(() => {
            this.isLoading = false
          })
      }
    }
  }
</script>

<style lang="scss" scoped>
  .container {
    min-height: 100vh;
    padding: 24rpx;
    background: #f7f8fa;
  }

  .content-card {
    padding: 32rpx 28rpx;
    background: #fff;
    border-radius: 16rpx;
  }

  .page-title {
    font-size: 34rpx;
    font-weight: 600;
    color: #111827;
  }

  .page-content {
    margin-top: 24rpx;
    font-size: 28rpx;
    color: #374151;
    line-height: 1.7;
  }

  .state {
    padding: 48rpx 24rpx;
    text-align: center;
    font-size: 28rpx;
    color: #6b7280;
  }

  .state-empty {
    padding-left: 0;
    padding-right: 0;
  }

  .state-error {
    color: #b42318;
  }
</style>
