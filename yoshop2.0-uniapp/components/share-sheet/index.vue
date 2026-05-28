<template>
  <view class="sharesheet" :class="{ show: modelValue }">
    <view class="mask-class sharesheet__mask" @click="onMaskClick"></view>
    <view class="sharesheet__container">
      <!-- 分享选项列表 -->
      <view class="sharesheet__list">
        <!-- 选项按钮: 发送给朋友(仅支持小程序) -->
        <!-- #ifdef MP -->
        <button class="share-item btn-normal" open-type="share" @click="handleCancel()">
          <view class="item-image" :style="{ backgroundColor: '#44DB74' }">
            <text class="iconfont icon-weixin"></text>
          </view>
          <view class="item-name">
            <text>发送给朋友</text>
          </view>
        </button>
        <!-- #endif -->
        <view class="share-item" @click="handleCopyLink()">
          <view class="item-image" :style="{ backgroundColor: '#38beec' }">
            <text class="iconfont icon-link"></text>
          </view>
          <view class="item-name">
            <text>复制链接</text>
          </view>
        </view>
        <!-- <view class="share-item">
          <view class="item-image" :style="{ backgroundColor: '#FE8A4F' }">
            <text class="iconfont icon-weibo"></text>
          </view>
          <view class="item-name">
            <text>新浪微博</text>
          </view>
        </view> -->
        <!-- <view class="share-item">
          <view class="item-image" :style="{ backgroundColor: '#56C0F2' }">
            <text class="iconfont icon-qq"></text>
          </view>
          <view class="item-name">
            <text>QQ好友</text>
          </view>
        </view> -->
        <!-- <view class="share-item">
          <view class="item-image" :style="{ backgroundColor: '#FFBB0D' }">
            <text class="iconfont icon-qzone"></text>
          </view>
          <view class="item-name">
            <text>QQ空间</text>
          </view>
        </view> -->
      </view>
      <!-- 取消按钮 -->
      <view v-if="cancelText" class="sharesheet__footer" @click="handleCancel()">
        <view class="btn-cancel">{{ cancelText }}</view>
      </view>
    </view>
  </view>
</template>

<!-- 参考的uniapp文档 -->
<!-- https://uniapp.dcloud.io/component/button?id=button -->
<!-- https://uniapp.dcloud.io/api/plugins/share -->

<script>
  import Config from '@/core/config'
  import { getCurrentPage, buildUrL } from '@/core/app'
  import { inArray } from '@/utils/util'
  import StoreModel from '@/common/model/Store'

  export default {
    name: 'ShareSheet',
    components: {},
    emits: ['update:modelValue'],
    props: {
      // 控制组件显示隐藏
      modelValue: {
        Type: Boolean,
        default: false
      },
      // 点击遮罩层取消
      cancelWithMask: {
        type: Boolean,
        default: true
      },
      // 分享链接的标题
      shareTitle: {
        type: String,
        default: '商品分享'
      },
      // 分享链接的封面图
      shareImageUrl: {
        type: String,
        default: ''
      },
      // 取消按钮文字
      cancelText: {
        type: String,
        default: '关闭'
      }
    },
    data() {
      return {
        
      }
    },

    // 初始化方法
    created() {
      this.initSharesheet()
    },

    methods: {

      // 初始化选择项
      initSharesheet() {},

      // 点击遮罩层(关闭菜单)
      onMaskClick() {
        if (this.cancelWithMask) {
          this.handleCancel()
        }
      },

      // 获取分享链接 (H5外链)
      getShareUrl() {
        const { path, query } = getCurrentPage()
        return new Promise((resolve, reject) => {
          // 获取h5站点地址
          StoreModel.h5Url().then(baseUrl => {
            // 生成完整的分享链接
            const shareUrl = buildUrL(baseUrl, path, query)
            resolve(shareUrl)
          })
        })
      },

      // 复制商品链接
      handleCopyLink() {
        const app = this
        app.getShareUrl().then(shareUrl => {
          // 复制到剪贴板
          uni.setClipboardData({
            data: shareUrl,
            success: () => app.$toast('链接复制成功，快去发送给朋友吧~'),
            fail: ({ errMsg }) => app.$toast('复制失败 ' + errMsg),
            complete: () => app.handleCancel()
          })
        })
      },

      // 关闭菜单
      handleCancel() {
        this.$emit('update:modelValue', false)
      }

    }
  }
</script>

<style lang="scss" scoped>
  .sharesheet {
    background-color: #f8f8f8;
    font-size: 28rpx;
  }

  .sharesheet__mask {
    position: fixed;
    top: var(--window-top);
    left: var(--window-left);
    right: var(--window-right);
    bottom: var(--window-bottom);
    z-index: 12;
    background: rgba(0, 0, 0, 0.7);
    display: none;
  }

  .sharesheet__container {
    position: fixed;
    left: var(--window-left);
    right: var(--window-right);
    bottom: var(--window-bottom);
    background: #ffffff;
    transform: translate3d(0, 50%, 0);
    transform-origin: center;
    transition: all 0.2s ease;
    z-index: 13;
    opacity: 0;
    visibility: hidden;
    border-top-left-radius: 26rpx;
    border-top-right-radius: 26rpx;
    padding: 50rpx 30rpx 0 30rpx;
    // 设置ios刘海屏底部横线安全区域
    padding-bottom: calc(constant(safe-area-inset-bottom) + 30rpx);
    padding-bottom: calc(env(safe-area-inset-bottom) + 30rpx);
  }

  .sharesheet__list {

    display: flex;
    flex-wrap: wrap;
    justify-content: flex-start;
    margin-bottom: -35rpx;

    .share-item {
      flex: 0 0 25%;
      margin-bottom: 40rpx;

      .item-name,
      .item-image {
        width: 140rpx;
        margin: 0 auto;
      }

      .item-image {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 86rpx;
        height: 86rpx;
        border-radius: 50%;
        color: #fff;
        font-size: 38rpx;
      }

      .item-name {
        margin-top: 12rpx;
        text-align: center;
        font-size: 26rpx;
      }
    }
  }

  .sharesheet__footer {
    background: #fff;
    margin-top: 40rpx;

    .btn-cancel {
      font-size: 28rpx;
      text-align: center;
    }
  }

  // 显示状态
  .show {
    .sharesheet__mask {
      display: block;
    }

    .sharesheet__container {
      opacity: 1;
      -webkit-transform: translate3d(0, 0, 0);
      transform: translate3d(0, 0, 0);
      visibility: visible;
    }
  }
</style>