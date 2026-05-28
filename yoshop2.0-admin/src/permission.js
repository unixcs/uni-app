import Vue from 'vue'
import router from './router'
import store from './store'

import NProgress from 'nprogress' // progress bar
import '@/components/NProgress/nprogress.less' // progress bar custom style
import notification from 'ant-design-vue/es/notification'
import { setDocumentTitle } from '@/utils/domUtil'
import { ACCESS_TOKEN } from '@/store/mutation-types'

NProgress.configure({ showSpinner: false }) // NProgress Configuration

// 登录验证白名单
const whiteList = ['/user/login']

// 默认的主页
const defaultRoutePath = '/store/index'

router.beforeEach((to, from, next) => {
  NProgress.start() // start progress bar
  // 设置当前页面标题
  to.meta && (typeof to.meta.title !== 'undefined' && setDocumentTitle(to.meta.title))
  if (Vue.ls.get(ACCESS_TOKEN)) {
    /* has token */
    if (to.path === '/user/login') {
      next({ path: defaultRoutePath })
      NProgress.done()
    } else {
      // 判断是否存在用户信息
      if (!store.getters.userInfo.admin_user_id) {
        store
          .dispatch('GetInfo')
          .then(data => {
            const roles = data.roles
            store.dispatch('GenerateRoutes', { roles })
              .then(routers => {
                // 根据roles权限生成可访问的路由表
                // 动态添加可访问路由表
                // router.addRoutes(routers)
                for (let x of routers) {
                  router.addRoute(x)
                }
                // 请求带有 redirect 重定向时，登录自动重定向到该地址
                const redirect = decodeURIComponent(from.query.redirect || to.path)
                if (to.path === redirect) {
                  // hack方法 确保addRoutes已完成 ,set the replace: true so the navigation will not leave a history record
                  next({ ...to, replace: true })
                } else {
                  // 跳转到目的路由
                  next({ path: redirect })
                }
              })
          })
          .catch(err => {
            // notification.error({
            //   message: '错误',
            //   description: '请求用户信息失败，请重试'
            // })
            // // 失败时，获取用户信息失败时，调用登出，来清空历史保留信息
            // store.dispatch('Logout').then(() => {
            //   next({ path: loginRoutePath, query: { redirect: to.fullPath } })
            // })
          })
      }
      next()
    }
  } else {
    if (whiteList.includes(to.path)) {
      // 在免登录白名单，直接进入
      next()
    } else {
      next({ path: '/user/login', query: { redirect: to.fullPath } })
      NProgress.done() // if current page is login will not trigger afterEach hook, so manually handle it
    }
  }
})

router.afterEach(() => {
  NProgress.done() // finish progress bar
})
