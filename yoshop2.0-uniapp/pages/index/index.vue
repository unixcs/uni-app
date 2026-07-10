<template>
  <view class="container" :style="appThemeStyle">
    <view v-if="isLoading" class="debug-state debug-loading debug-loading-center">
      首页加载中...
    </view>
    <!-- 店铺页面组件 -->
    <Page v-if="!isLoading && items.length" :items="items" />
    <view v-if="!isLoading && errorText" class="debug-state debug-error">
      {{ errorText }}
    </view>
    <view v-else-if="!isLoading && !items.length" class="debug-state debug-empty">
      首页数据已返回，但当前没有可渲染内容
    </view>
    <!-- 用户隐私保护提示（仅微信小程序） -->
    <!-- #ifdef MP-WEIXIN -->
    <PrivacyPopup v-if="!isLoading" :hideTabBar="true" @end="onPrivacyPopupEnd" />
    <FirstLoginPopup
      v-if="showFirstLoginPopup"
      :body="firstLoginPopupBody"
      :hideTabBar="true"
      @close="handleFirstLoginPopupClose"
    />
    <!-- #endif -->
  </view>
</template>

<script>
  import { setCartTabBadge, checkLogin } from '@/core/app'
  import * as Api from '@/api/page'
  import * as ApiUser from '@/api/user'
  import Page from '@/components/page'
  import PrivacyPopup from '@/components/privacy-popup'
  import FirstLoginPopup from '@/components/first-login-popup'

  export default {
    components: {
      Page,
      PrivacyPopup,
      FirstLoginPopup
    },
    data() {
      return {
        // 页面参数
        options: {},
        // 页面属性
        page: {},
        // 页面元素
        items: [],
        // 加载状态
        isLoading: true,
        // 错误提示
        errorText: '',
        // 加载超时计时器
        loadingTimer: null,
        // 微信隐私弹窗流程是否已结束
        privacyPopupReady: false,
        // 首页首登业务弹窗是否展示
        showFirstLoginPopup: false,
        // 首页首登业务弹窗正文
        firstLoginPopupBody: '',
        // 是否正在请求首页首登业务弹窗
        isCheckingFirstLoginPopup: false
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad(options) {
      // 当前页面参数
      this.options = options
      // 加载页面数据
      this.getPageData()
    },

    /**
     * 生命周期函数--监听页面显示
     */
    onShow() {
      // 更新购物车角标
      setCartTabBadge()
      // #ifdef MP-WEIXIN
      this.tryShowFirstLoginPopup()
      // #endif
    },

    methods: {

      /**
       * 加载页面数据
       * @param {Object} callback
       */
      getPageData(callback) {
        const app = this
        const pageId = app.options.pageId || 0
        app.isLoading = true
        app.errorText = ''
        if (app.loadingTimer) {
          clearTimeout(app.loadingTimer)
        }
        app.loadingTimer = setTimeout(() => {
          if (app.isLoading) {
            app.items = []
            app.errorText = '首页加载超时，请检查接口或网络'
            app.isLoading = false
          }
        }, 5000)
        Api.detail(pageId)
          .then(result => {
            // 设置页面数据
            const pageData = result && result.data && result.data.pageData ? result.data.pageData : {}
            app.page = pageData.page || {}
            app.items = app.normalizeItems(pageData.items)
            // 设置顶部导航栏栏
            app.setPageBar()
          })
          .catch(() => {
            app.items = []
            app.errorText = '首页加载失败，请稍后重试'
          })
          .finally(() => {
            if (app.loadingTimer) {
              clearTimeout(app.loadingTimer)
              app.loadingTimer = null
            }
            app.isLoading = false
            callback && callback()
          })
      },

      /**
       * 设置顶部导航栏
       */
      setPageBar() {
        const { page } = this
        if (!page || !page.params || !page.style) {
          return
        }
        // 设置页面标题
        uni.setNavigationBarTitle({
          title: page.params.title
        })
        // 设置navbar标题、颜色
        uni.setNavigationBarColor({
          frontColor: page.style.titleTextColor === 'white' ? '#ffffff' : '#000000',
          backgroundColor: page.style.titleBackgroundColor
        })
      },

      normalizeItems(items) {
        if (!Array.isArray(items)) {
          return []
        }
        return items
          .filter(item => item && typeof item === 'object' && typeof item.type === 'string')
          .map(item => ({
            ...item,
            style: item.style && typeof item.style === 'object' ? item.style : {},
            params: item.params && typeof item.params === 'object' ? item.params : {},
            data: Array.isArray(item.data) ? item.data : []
          }))
      },

      onPrivacyPopupEnd() {
        this.privacyPopupReady = true
        this.tryShowFirstLoginPopup()
      },

      tryShowFirstLoginPopup() {
        if (this.isLoading || !this.privacyPopupReady || this.showFirstLoginPopup || this.isCheckingFirstLoginPopup || !checkLogin()) {
          return
        }
        this.isCheckingFirstLoginPopup = true
        ApiUser.firstLoginPopup({}, { load: false, isPrompt: false })
          .then(result => {
            const popup = result.data && result.data.popup ? result.data.popup : {}
            const body = popup.body || ''
            this.firstLoginPopupBody = body
            this.showFirstLoginPopup = !!popup.show && !!body
          })
          .catch(err => {
            if (err.result && err.result.status === 401) {
              return
            }
          })
          .finally(() => {
            this.isCheckingFirstLoginPopup = false
          })
      },

      handleFirstLoginPopupClose() {
        this.showFirstLoginPopup = false
      }

    },

    /**
     * 下拉刷新
     */
    onPullDownRefresh() {
      // 获取首页数据
      this.getPageData(() => {
        uni.stopPullDownRefresh()
      })
    },

    /**
     * 分享当前页面
     */
    onShareAppMessage() {
      const app = this
      const { page } = app
      return {
        title: page.params.shareTitle,
        path: "/pages/index/index?" + app.$getShareUrlParams()
      }
    },

    /**
     * 分享到朋友圈
     * 本接口为 Beta 版本，暂只在 Android 平台支持，详见分享到朋友圈 (Beta)
     * https://developers.weixin.qq.com/miniprogram/dev/framework/open-ability/share-timeline.html
     */
    onShareTimeline() {
      const app = this
      const { page } = app
      return {
        title: page.params.shareTitle,
        path: "/pages/index/index?" + app.$getShareUrlParams()
      }
    }

  }
</script>

<style lang="scss" scoped>
  .container {
    background: #fff;
    min-height: 100vh;
  }

  .debug-state {
    margin: 24rpx;
    padding: 24rpx;
    border-radius: 12rpx;
    font-size: 26rpx;
    line-height: 1.6;
  }

  .debug-error {
    color: #b42318;
    background: #fef3f2;
  }

  .debug-loading {
    color: #475467;
    background: #f8fafc;
  }

  .debug-loading-center {
    min-height: 180rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32rpx;
    color: #111827;
    font-weight: 600;
  }

  .debug-empty {
    color: #475467;
    background: #f9fafb;
  }
</style>
