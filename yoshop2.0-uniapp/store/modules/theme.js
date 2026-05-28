import storage from '@/utils/storage'
import { isEmpty } from '@/utils/util'
import { APP_THEME } from '@/store/mutation-types'

const theme = {
  state: {
    // 当前自定义主题
    appTheme: {
      mainBg: '#fa2209',
      mainBg2: '#ff6335',
      mainText: '#ffffff',
      viceBg: '#ffb100',
      viceBg2: '#ffb900',
      viceText: '#ffffff',
    },
  },

  mutations: {
    SET_APP_THEME: (state, value) => {
      if (!isEmpty(value)) {
        state.appTheme = value
      }
    }
  },

  actions: {

    // 记录自定义主题
    SetAppTheme({ commit }, value) {
      return new Promise((resolve, reject) => {
        storage.set(APP_THEME, value)
        commit('SET_APP_THEME', value)
        resolve()
      })
    }

  }
}

export default theme
