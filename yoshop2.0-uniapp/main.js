import App from './App'
import { createSSRApp } from 'vue'
import store from './store'
import bootstrap from './core/bootstrap'
import mixin from './core/mixins/app'
import uView from './uni_modules/vk-uview-ui'
import { navTo, showToast, showSuccess, showError, getShareUrlParams, checkModuleKey, checkModules } from './core/app'

// 不能修改createApp方法名，不能修改从Vue中导入的createSSRApp
export function createApp() {
  // 创建应用实例
  const app = createSSRApp({ ...App, store, created: bootstrap })

  // 挂载全局函数
  app.config.globalProperties.$toast = showToast
  app.config.globalProperties.$success = showSuccess
  app.config.globalProperties.$error = showError
  app.config.globalProperties.$navTo = navTo
  app.config.globalProperties.$getShareUrlParams = getShareUrlParams
  app.config.globalProperties.$checkModule = checkModuleKey
  app.config.globalProperties.$checkModules = checkModules

  // 使用 uView UI
  app.use(uView)

  // 全局mixin
  app.mixin(mixin)

  return { app }
}