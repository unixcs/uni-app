import store from '@/store'
import storage from '@/utils/storage'
import Config from '@/core/config'
import platform from '@/core/platform'
import { ACCESS_TOKEN, USER_ID, APP_THEME } from '@/store/mutation-types'

export default function YoShop2() {
  // 当前运行的终端
  store.commit('SET_PLATFORM', platform)
  // 用户认证token
  store.commit('SET_TOKEN', storage.get(ACCESS_TOKEN))
  // 当前用户ID
  store.commit('SET_USER_ID', storage.get(USER_ID))
  // 全局自定义主题
  store.commit('SET_APP_THEME', storage.get(APP_THEME))
}
