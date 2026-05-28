import { computed } from 'vue'
import { useStore, mapGetters } from 'vuex'
import { styleObj2Str } from '@/core/app'
import store from '@/store/index'
import platform from '@/core/platform'

export default {
  data() {
    return {
      platform
    }
  },
  computed: {
    appTheme: () => store.getters.appTheme,
    appThemeStyle: () => styleObj2Str(store.getters.appTheme)
  },
  mounted() {
    // #ifdef H5
    // 微信公众号端隐藏 navigationBar  (解决双标题栏问题)
    if (this.platform === 'WXOFFICIAL') {
      this.hideNavigationBar()
    }
    // #endif
  },
  methods: {
    // #ifdef H5
    // 隐藏 navigationBar
    hideNavigationBar() {
      this.$nextTick(() => {
        const navTitleDom = document.getElementsByTagName('uni-page-head')
        if (navTitleDom.length) {
          navTitleDom[0].style.display = 'none'
          document.body.style.setProperty('--window-top', '0px');
        }
      })
    },
    // #endif
    // 自定义主题样式
    customThemeStyle(styleObj) {
      return styleObj2Str({ ...store.getters.appTheme, ...styleObj })
    }
  }
}