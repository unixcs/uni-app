import { createStore } from 'vuex'
import { app, user, theme, page } from './modules'
import getters from './getters'

const store = createStore({
  modules: {
    app,
    user,
    theme,
    page
  },
  state: {

  },
  mutations: {

  },
  actions: {

  },
  getters
})
export default store