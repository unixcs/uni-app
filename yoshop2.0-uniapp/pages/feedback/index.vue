<template>
  <view class="container" :style="appThemeStyle">
    <u-tabs :list="tabs" :is-scroll="false" v-model="curTab" :active-color="appTheme.mainBg" :duration="0.2" @change="onChangeTab" />

    <view v-if="curTab === 0" class="form-wrapper">
      <view class="form-card b-f m-top20">
        <view class="form-item dis-flex flex-y-center">
          <view class="label">问题类型</view>
          <picker class="picker flex-box" mode="selector" :range="issueTypes" range-key="name" :value="currentIssueTypeIndex" @change="onChangeIssueType">
            <view class="picker-value">{{ currentIssueTypeName }}</view>
          </picker>
        </view>
        <view class="form-item textarea-item">
          <view class="label">反馈内容</view>
          <textarea class="textarea" v-model="formData.content" maxlength="2000" placeholder="请详细描述您遇到的问题、建议或投诉内容" placeholderStyle="color:#bdbdbd" />
        </view>
        <view class="form-item dis-flex flex-y-center">
          <view class="label">联系方式</view>
          <input class="input flex-box" v-model="formData.mobile" maxlength="20" placeholder="请填写联系方式，便于我们联系您" />
        </view>
      </view>

      <view class="row-voucher b-f m-top20">
        <view class="row-title">上传凭证 (最多6张)</view>
        <view class="image-list clearfix">
          <view class="image-preview" v-for="(image, imageIndex) in imageList" :key="imageIndex">
            <text class="image-delete iconfont icon-shanchu" @click="deleteImage(imageIndex)"></text>
            <image class="image" mode="aspectFill" :src="image.path || image.tempFilePath"></image>
          </view>
          <view v-if="imageList.length < maxImageLength" class="image-picker" @click="chooseImage()">
            <text class="choose-icon iconfont icon-camera"></text>
            <text class="choose-text">上传图片</text>
          </view>
        </view>
      </view>

      <view class="tips-card b-f m-top20">
        <view class="tips-title">说明</view>
        <view class="tips-text">反馈/投诉提交后会进入处理队列，您可在“反馈记录”中查看处理状态与官方回复。</view>
      </view>

      <view class="footer-fixed">
        <view class="btn-wrapper">
          <view class="btn-item btn-item-main" :class="{ disabled }" @click="handleSubmit">提交反馈</view>
        </view>
      </view>
    </view>

    <view v-else class="record-wrapper">
      <view v-if="recordList.data.length" class="widget-list">
        <view v-for="(item, index) in recordList.data" :key="index" class="widget-detail b-f m-top20" @click="handleTargetDetail(item.feedback_id)">
          <view class="row-head dis-flex flex-y-center">
            <view class="flex-box row-title-text">{{ item.issue_type_text }}</view>
            <text class="status" :class="[`status-${item.status}`]">{{ item.status_text }}</text>
          </view>
          <view class="row-content">{{ item.content }}</view>
          <view class="row-foot dis-flex flex-y-center">
            <view class="flex-box col-9">{{ item.create_time }}</view>
            <view class="detail-btn">查看详情</view>
          </view>
        </view>
        <view v-if="canLoadMore" class="load-more" @click="loadMoreRecords">点击加载更多</view>
        <view v-else class="load-more done">没有更多了</view>
      </view>
      <view v-else-if="recordLoaded && !recordLoading" class="empty-state">暂无反馈记录</view>
      <view v-if="recordLoading" class="loading-state">加载中...</view>
    </view>
  </view>
</template>

<script>
import * as UploadApi from '@/api/upload'
import * as FeedbackApi from '@/api/feedback'
import * as UserApi from '@/api/user'
import { checkLogin, showSuccess, showToast } from '@/core/app'

const maxImageLength = 6
const tabs = [{ name: '我要反馈' }, { name: '反馈记录' }]
const issueTypes = [
  { name: '功能建议', value: 10 },
  { name: '功能异常', value: 20 },
  { name: '体验问题', value: 30 },
  { name: '订单问题', value: 40 },
  { name: '其他问题', value: 50 },
  { name: '投诉反馈', value: 60 }
]

export default {
  data () {
    return {
      tabs,
      issueTypes,
      curTab: 0,
      formData: {
        issueType: 10,
        content: '',
        mobile: ''
      },
      imageList: [],
      maxImageLength,
      disabled: false,
      recordList: {
        data: [],
        current_page: 1,
        last_page: 1,
        total: 0
      },
      recordLoaded: false,
      recordLoading: false
    }
  },
  computed: {
    currentIssueTypeIndex () {
      const index = this.issueTypes.findIndex(item => item.value === Number(this.formData.issueType))
      return index > -1 ? index : 0
    },
    currentIssueTypeName () {
      const current = this.issueTypes[this.currentIssueTypeIndex]
      return current ? current.name : '请选择问题类型'
    },
    canLoadMore () {
      return this.recordList.current_page < this.recordList.last_page
    }
  },
  onShow () {
    this.initPage()
  },
  onPullDownRefresh () {
    if (this.curTab === 1) {
      this.refreshRecords().finally(() => {
        uni.stopPullDownRefresh()
      })
      return
    }
    uni.stopPullDownRefresh()
  },
  onReachBottom () {
    if (this.curTab === 1 && this.canLoadMore) {
      this.loadMoreRecords()
    }
  },
  methods: {
    initPage () {
      if (checkLogin() && !this.formData.mobile) {
        UserApi.info({}, { load: false, isPrompt: false })
          .then(result => {
            const userInfo = result.data.userInfo || {}
            if (!this.formData.mobile) {
              this.formData.mobile = userInfo.mobile || ''
            }
          })
          .catch(() => {})
      }
    },
    onChangeTab (index) {
      this.curTab = index
      if (index === 1 && !this.recordLoaded) {
        this.refreshRecords()
      }
    },
    onChangeIssueType ({ detail }) {
      const target = this.issueTypes[Number(detail.value)]
      this.formData.issueType = target ? target.value : 10
    },
    chooseImage () {
      const app = this
      const oldImageList = app.imageList
      // #ifndef MP-WEIXIN
      uni.chooseImage({
        count: maxImageLength - oldImageList.length,
        sizeType: ['original', 'compressed'],
        sourceType: ['album', 'camera'],
        success ({ tempFiles }) {
          app.imageList = oldImageList.concat(tempFiles.map(app.normalizeLocalImage))
        }
      })
      // #endif
      // #ifdef MP-WEIXIN
      uni.chooseMedia({
        count: maxImageLength - oldImageList.length,
        mediaType: ['image'],
        sourceType: ['album', 'camera'],
        sizeType: ['original', 'compressed'],
        success ({ tempFiles }) {
          app.imageList = oldImageList.concat(tempFiles.map(app.normalizeLocalImage))
        }
      })
      // #endif
    },
    normalizeLocalImage (image) {
      return {
        ...image,
        path: image.path || image.tempFilePath || '',
        tempFilePath: image.tempFilePath || image.path || '',
        uploadedFileId: Number(image.uploadedFileId || 0)
      }
    },
    getUploadedImageIds () {
      return this.imageList
        .map(item => Number(item.uploadedFileId || 0))
        .filter(item => item > 0)
    },
    deleteImage (imageIndex) {
      this.imageList.splice(imageIndex, 1)
    },
    handleSubmit () {
      if (this.disabled) {
        return false
      }
      const content = (this.formData.content || '').trim()
      const mobile = (this.formData.mobile || '').trim()
      if (!content) {
        showToast('请填写反馈内容')
        return false
      }
      if (!mobile) {
        showToast('请填写联系方式')
        return false
      }
      this.disabled = true
      const submitAction = imageIds => {
        FeedbackApi.create({
          ...this.formData,
          content,
          mobile,
          imageIds
        })
          .then(result => {
            showSuccess(result.message || '提交成功', () => {
              const currentMobile = this.formData.mobile
              this.formData = {
                issueType: 10,
                content: '',
                mobile: currentMobile
              }
              this.imageList = []
              this.curTab = 1
              this.refreshRecords()
            })
          })
          .finally(() => {
            this.disabled = false
          })
      }

      if (this.imageList.length > 0) {
        this.uploadFiles()
          .then(fileIds => {
            submitAction(fileIds)
          })
          .catch(() => {
            this.disabled = false
          })
        return
      }
      submitAction([])
    },
    uploadFiles () {
      const pendingImages = this.imageList.filter(item => !Number(item.uploadedFileId || 0))
      if (pendingImages.length === 0) {
        return Promise.resolve(this.getUploadedImageIds())
      }
      return UploadApi.image(pendingImages)
        .then(fileIds => {
          let pendingIndex = 0
          this.imageList = this.imageList.map(item => {
            if (Number(item.uploadedFileId || 0) > 0) {
              return item
            }
            const uploadedFileId = Number(fileIds[pendingIndex] || 0)
            pendingIndex += 1
            return {
              ...item,
              uploadedFileId
            }
          })
          const uploadedImageIds = this.getUploadedImageIds()
          if (uploadedImageIds.length !== this.imageList.length) {
            showToast('图片上传失败，请重试')
            return Promise.reject(new Error('feedback image upload mismatch'))
          }
          return uploadedImageIds
        })
    },
    refreshRecords () {
      this.recordList = {
        data: [],
        current_page: 1,
        last_page: 1,
        total: 0
      }
      this.recordLoaded = false
      return this.fetchRecords(1)
    },
    fetchRecords (page = 1) {
      this.recordLoading = true
      return FeedbackApi.list({ page }, { load: page === 1 })
        .then(result => {
          const list = result.data.list || {}
          const data = list.data || []
          this.recordList = {
            ...list,
            data: page === 1 ? data : this.recordList.data.concat(data)
          }
          this.recordLoaded = true
          return list
        })
        .finally(() => {
          this.recordLoading = false
        })
    },
    loadMoreRecords () {
      if (this.recordLoading || !this.canLoadMore) {
        return false
      }
      return this.fetchRecords(this.recordList.current_page + 1)
    },
    handleTargetDetail (feedbackId) {
      this.$navTo('pages/feedback/detail', { feedbackId })
    }
  }
}
</script>

<style lang="scss" scoped>
.form-wrapper {
  padding-bottom: 140rpx;
}
.form-card,
.tips-card,
.row-voucher,
.widget-detail {
  margin-left: 20rpx;
  margin-right: 20rpx;
  border-radius: 16rpx;
}
.form-card {
  padding: 0 24rpx;
  .form-item {
    min-height: 96rpx;
    border-bottom: 1rpx solid #f5f5f5;
    &.textarea-item {
      padding: 24rpx 0;
      min-height: 280rpx;
    }
    &:last-child {
      border-bottom: none;
    }
  }
  .label {
    width: 140rpx;
    color: #333;
    font-size: 28rpx;
  }
  .picker,
  .input {
    min-height: 96rpx;
    display: flex;
    align-items: center;
    color: #333;
    font-size: 28rpx;
  }
  .picker-value {
    color: #333;
  }
  .textarea {
    width: 100%;
    min-height: 200rpx;
    line-height: 1.7;
    font-size: 28rpx;
  }
}
.row-voucher {
  padding: 24rpx;
  .row-title {
    margin-bottom: 24rpx;
    font-size: 28rpx;
    color: #333;
  }
}
.image-list {
  margin-bottom: -15rpx;
}
.image-preview,
.image-picker {
  position: relative;
  float: left;
  width: 180rpx;
  height: 180rpx;
  margin: 0 15rpx 15rpx 0;
  border-radius: 12rpx;
  overflow: hidden;
}
.image-preview:nth-child(3n),
.image-picker:nth-child(3n) {
  margin-right: 0;
}
.image-preview .image {
  display: block;
  width: 100%;
  height: 100%;
}
.image-delete {
  position: absolute;
  top: 10rpx;
  right: 10rpx;
  z-index: 2;
  width: 36rpx;
  height: 36rpx;
  line-height: 36rpx;
  text-align: center;
  color: #fff;
  background: rgba(0, 0, 0, 0.45);
  border-radius: 50%;
  font-size: 24rpx;
}
.image-picker {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  background: #fafafa;
  border: 1rpx dashed #d9d9d9;
}
.choose-icon {
  font-size: 44rpx;
  color: #999;
}
.choose-text {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999;
}
.tips-card {
  padding: 24rpx;
  .tips-title {
    font-size: 28rpx;
    color: #333;
    margin-bottom: 14rpx;
  }
  .tips-text {
    line-height: 1.8;
    color: #666;
    font-size: 26rpx;
  }
}
.record-wrapper {
  padding-bottom: 40rpx;
}
.widget-detail {
  padding: 24rpx;
}
.row-head {
  margin-bottom: 18rpx;
}
.row-title-text {
  font-size: 30rpx;
  color: #333;
  font-weight: 500;
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
.row-content {
  color: #555;
  font-size: 27rpx;
  line-height: 1.7;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.row-foot {
  margin-top: 20rpx;
  font-size: 24rpx;
}
.detail-btn {
  color: #666;
}
.load-more,
.empty-state,
.loading-state {
  padding: 28rpx 0;
  text-align: center;
  color: #999;
  font-size: 24rpx;
}
.load-more.done {
  color: #bbb;
}
.footer-fixed {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #fff;
  padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
  box-shadow: 0 -8rpx 24rpx rgba(0, 0, 0, 0.04);
}
.btn-wrapper {
  display: flex;
}
.btn-item {
  flex: 1;
  height: 84rpx;
  line-height: 84rpx;
  text-align: center;
  border-radius: 42rpx;
  color: #fff;
  background: #e8c269;
  &.disabled {
    opacity: 0.6;
  }
}
</style>

