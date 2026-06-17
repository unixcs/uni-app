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
    <PrivacyPopup v-if="!isLoading" :hideTabBar="true" />
    <!-- #endif -->
  </view>
</template>

<script>
  import { setCartTabBadge } from '@/core/app'
  import * as Api from '@/api/page'
  import Page from '@/components/page'
  import PrivacyPopup from '@/components/privacy-popup'

  const App = getApp()

  export default {
    components: {
      Page,
      PrivacyPopup
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
        loadingTimer: null
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
            console.info('首页 page/detail 返回', result)
            // 设置页面数据
            const { data: { pageData } } = result
            app.page = pageData.page
            app.items = Array.isArray(pageData.items) ? pageData.items : []
            // 设置顶部导航栏栏
            app.setPageBar()
          })
          .catch(err => {
            console.error('首页加载失败', err)
            app.items = []
            app.errorText = '首页加载失败，请看控制台或网络请求'
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
          console.warn('首页 page 数据不完整', page)
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
