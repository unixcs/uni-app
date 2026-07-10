<template>
  <view class="container" :style="appThemeStyle">
    <view class="detail-card b-f m-top20">
      <view class="detail-header dis-flex flex-y-center">
        <view class="flex-box">
          <view class="title">{{ detail.issue_type_text || '--' }}</view>
          <view class="time">{{ detail.create_time || '--' }}</view>
        </view>
        <text class="status" :class="[`status-${detail.status}`]">{{ detail.status_text || '--' }}</text>
      </view>
    </view>

    <view class="detail-card b-f m-top20">
      <view class="section-title">反馈内容</view>
      <view class="content-text">{{ detail.content || '--' }}</view>
    </view>

    <view class="detail-card b-f m-top20">
      <view class="info-row dis-flex">
        <view class="info-label">联系方式</view>
        <view class="flex-box">{{ detail.mobile || '--' }}</view>
      </view>
      <view class="info-row dis-flex">
        <view class="info-label">处理状态</view>
        <view class="flex-box">{{ detail.status_text || '--' }}</view>
      </view>
      <view class="info-row dis-flex">
        <view class="info-label">更新时间</view>
        <view class="flex-box">{{ detail.update_time || '--' }}</view>
      </view>
      <view class="info-row dis-flex">
        <view class="info-label">回复时间</view>
        <view class="flex-box">{{ detail.reply_time || '--' }}</view>
      </view>
    </view>

    <view class="detail-card b-f m-top20">
      <view class="section-title">上传凭证</view>
      <view v-if="detail.images && detail.images.length" class="image-list clearfix">
        <view v-for="(item, index) in detail.images" :key="index" class="image-preview" @click="handlePreview(index)">
          <image class="image" :src="item.preview_url" mode="aspectFill"></image>
        </view>
      </view>
      <view v-else class="empty-text">未上传图片</view>
    </view>

    <view class="detail-card b-f m-top20 m-bot20">
      <view class="section-title">官方回复</view>
      <view class="content-text">{{ detail.reply_content || replyFallbackText }}</view>
    </view>
  </view>
</template>

<script>
import * as FeedbackApi from '@/api/feedback'

export default {
  data () {
    return {
      feedbackId: 0,
      detail: {
        images: []
      }
    }
  },
  computed: {
    replyFallbackText () {
      const status = Number(this.detail.status || 0)
      if (status === 40) {
        return '该反馈已关闭，暂无官方回复'
      }
      return '暂未回复，请耐心等待'
    }
  },
  onLoad ({ feedbackId }) {
    this.feedbackId = Number(feedbackId || 0)
    this.getDetail()
  },
  methods: {
    getDetail () {
      FeedbackApi.detail(this.feedbackId)
        .then(result => {
          this.detail = result.data.detail || { images: [] }
        })
    },
    handlePreview (current) {
      const urls = (this.detail.images || []).map(item => item.preview_url)
      uni.previewImage({
        current,
        urls
      })
    }
  }
}
</script>

<style lang="scss" scoped>
.detail-card {
  margin-left: 20rpx;
  margin-right: 20rpx;
  padding: 24rpx;
  border-radius: 16rpx;
}
.detail-header {
  .title {
    font-size: 32rpx;
    color: #333;
    font-weight: 500;
  }
  .time {
    margin-top: 10rpx;
    color: #999;
    font-size: 24rpx;
  }
}
.status {
  padding: 8rpx 18rpx;
  border-radius: 999rpx;
  font-size: 24rpx;
  &.status-10 {
    color: #666;
    background: #f5f5f5;
  }
  &.status-20 {
    color: #d48806;
    background: #fff7e6;
  }
  &.status-30 {
    color: #389e0d;
    background: #f6ffed;
  }
  &.status-40 {
    color: #595959;
    background: #fafafa;
  }
}
.section-title {
  margin-bottom: 18rpx;
  font-size: 28rpx;
  color: #333;
  font-weight: 500;
}
.content-text {
  white-space: pre-wrap;
  line-height: 1.8;
  font-size: 27rpx;
  color: #555;
}
.info-row {
  padding: 10rpx 0;
  font-size: 27rpx;
  color: #555;
  .info-label {
    width: 150rpx;
    color: #999;
  }
}
.image-list {
  margin-bottom: -15rpx;
}
.image-preview {
  float: left;
  width: 200rpx;
  height: 200rpx;
  margin: 0 15rpx 15rpx 0;
  border-radius: 12rpx;
  overflow: hidden;
}
.image-preview:nth-child(3n) {
  margin-right: 0;
}
.image {
  width: 100%;
  height: 100%;
}
.empty-text {
  color: #999;
  font-size: 26rpx;
}
</style>

