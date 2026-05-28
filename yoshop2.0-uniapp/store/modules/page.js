// import { isEmpty } from '@/utils/util'
import { cloneObj } from '@/utils/util'

const page = {
  state: {
    // 当前query参数
    query: null
  },

  mutations: {
    SET_QUERY_PARAM: (state, value) => {
      state.query = value
    }
  },

  actions: {

    // 记录query参数 (用于switchTab传参)
    SetQueryParam({ commit }, value) {
      commit('SET_QUERY_PARAM', value)
      return 'sdadadad'
    },

    // 获取query参数并且清空
    OnceQueryParam({ commit }, value) {
      const res = this.getters.pageQuery === null ? null : cloneObj(this.getters.pageQuery)
      commit('SET_QUERY_PARAM', null)
      return res
    },

  }
}

export default page