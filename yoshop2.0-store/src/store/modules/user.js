import storage from 'store'
import { login, logout } from '@/api/login'
import { getInfo } from '@/api/store/user'
import { ACCESS_TOKEN } from '@/store/mutation-types'
import { welcome } from '@/utils/util'
import { resetRouter } from '@/router'

// 创建路由权限列表
const createPermissionList = roles => {
  roles.permissions = Array.isArray(roles.permissions) ? roles.permissions : []
  roles.permissions.forEach(item => {
    item.actionList = []
    if (item.actionEntitySet && item.actionEntitySet.length > 0) {
      item.actionList = item.actionEntitySet.map(action => action.action)
    }
  })
  roles.permissionList = roles.permissions.map(item => item.permissionId)
}

const user = {
  state: {
    token: '',
    name: '',
    welcome: '',
    // avatar: '',
    roles: {

    },
    info: {}
  },

  mutations: {
    SET_TOKEN: (state, token) => {
      state.token = token
    },
    SET_NAME: (state, { name, welcome }) => {
      state.name = name
      state.welcome = welcome
    },
    SET_AVATAR: (state, avatar) => {
      state.avatar = avatar
    },
    SET_ROLES: (state, roles) => {
      state.roles = roles
    },
    SET_INFO: (state, info) => {
      state.info = info
    },
    RESET_USER_STATE: (state) => {
      state.token = ''
      state.name = ''
      state.welcome = ''
      state.roles = {}
      state.info = {}
    }
  },

  actions: {

    // 用户登录
    Login ({ commit }, userInfo) {
      return new Promise((resolve, reject) => {
        resetRouter()
        commit('RESET_USER_STATE')
        commit('RESET_ROUTERS')
        storage.remove(ACCESS_TOKEN)
        login(userInfo)
          .then(response => {
            const data = response.data
            // token保存7天
            storage.set(ACCESS_TOKEN, data.token, 7 * 24 * 60 * 60 * 1000)
            commit('SET_TOKEN', data.token)
            resolve(response)
          })
          .catch((error) => {
            reject(error)
          })
      })
    },

    // 获取用户信息
    GetInfo ({ commit }) {
      return new Promise((resolve, reject) => {
        getInfo().then(response => {
          const data = response.data
          // 创建路由权限列表
          createPermissionList(data.roles)
          // 记录状态值
          commit('SET_ROLES', data.roles)
          commit('SET_INFO', data.userInfo)
          commit('SET_NAME', { name: data.userInfo.real_name, welcome: welcome() })
          // commit('SET_AVATAR', result.avatar)
          resolve(data)
        }).catch(error => {
          reject(error)
        })
      })
    },

    // 退出登录
    Logout ({ commit, state }) {
      return new Promise((resolve, reject) => {
        logout(state.token)
          .then(() => {
            // 清理用户信息
            resetRouter()
            commit('RESET_USER_STATE')
            commit('RESET_ROUTERS')
            storage.remove(ACCESS_TOKEN)
            resolve()
          })
      })
    },

    // 超管后台自动登录
    SuperLogin ({ commit }, loginInfo) {
      // token保存7天
      storage.set(ACCESS_TOKEN, loginInfo['token'], 7 * 24 * 60 * 60 * 1000)
      commit('SET_TOKEN', loginInfo['token'])
    }

  }
}

export default user
