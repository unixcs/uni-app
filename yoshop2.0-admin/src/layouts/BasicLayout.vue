<template>
  <a-layout :class="['layout', device]">
    <!-- layout header -->
    <global-header
      :mode="layoutMode"
      :menus="menus"
      :theme="navTheme"
      :collapsed="collapsed"
      :device="device"
      @toggle="toggle"
    />

    <!-- layout axis -->
    <a-layout :class="[layoutMode, `content-width-${contentWidth}`]">
      <div class="container" :style="{ paddingLeft: contentPaddingLeft}">
        <!-- layout sidemenu -->
        <side-menu
          v-if="isSideMenu()"
          mode="inline"
          :menus="menus"
          :theme="navTheme"
          :collapsed="collapsed"
          :collapsible="true"
        ></side-menu>

        <!-- layout content -->
        <a-layout-content :style="{ height: '100%' }">
          <transition name="page-transition">
            <route-view />
          </transition>
        </a-layout-content>

        <!-- 主题修改器 -->
        <!-- Setting Drawer (show in development mode) -->
        <!-- <setting-drawer v-if="!production && false"></setting-drawer> -->
      </div>
    </a-layout>

    <!-- layout footer -->
    <a-layout-footer>
      <global-footer />
    </a-layout-footer>
  </a-layout>
</template>

<script>
import { triggerWindowResizeEvent } from '@/utils/util'
import { mapState, mapActions } from 'vuex'
import { mixin, mixinDevice } from '@/utils/mixin'
import config from '@/config/defaultSettings'

import RouteView from './RouteView'
import SideMenu from '@/components/Menu/SideMenu'
import GlobalHeader from '@/components/GlobalHeader'
import GlobalFooter from '@/components/GlobalFooter'
import { convertRoutes } from '@/utils/routeConvert'

export default {
  name: 'BasicLayout',
  mixins: [mixin, mixinDevice],
  components: {
    RouteView,
    SideMenu,
    GlobalHeader,
    GlobalFooter
  },
  data () {
    return {
      production: config.production,
      collapsed: false,
      menus: []
    }
  },
  computed: {
    ...mapState({
      // 动态主路由
      mainMenu: state => state.permission.addRouters
    }),
    contentPaddingLeft () {
      if (!this.fixSidebar || this.isMobile()) {
        return '0'
      }
      if (this.sidebarOpened) {
        return '256px'
      }
      return '80px'
    }
  },
  watch: {
    sidebarOpened (val) {
      this.collapsed = !val
    }
  },
  created () {
    const routes = convertRoutes(this.mainMenu.find(item => item.path === '/'))
    this.menus = (routes && routes.children) || []
    this.collapsed = !this.sidebarOpened
  },
  mounted () {
    const userAgent = navigator.userAgent
    if (userAgent.indexOf('Edge') > -1) {
      this.$nextTick(() => {
        this.collapsed = !this.collapsed
        setTimeout(() => {
          this.collapsed = !this.collapsed
        }, 16)
      })
    }
  },
  methods: {
    ...mapActions(['setSidebar']),
    toggle () {
      this.collapsed = !this.collapsed
      this.setSidebar(!this.collapsed)
      triggerWindowResizeEvent()
    },
    paddingCalc () {
      let left = ''
      if (this.sidebarOpened) {
        left = this.isDesktop() ? '256px' : '80px'
      } else {
        left = (this.isMobile() && '0') || ((this.fixSidebar && '80px') || '0')
      }
      return left
    },
    menuSelect () {
    },
    drawerClose () {
      this.collapsed = false
    }
  }
}
</script>

<style lang="less" scoped>
/*
 * The following styles are auto-applied to elements with
 * transition="page-transition" when their visibility is toggled
 * by Vue.js.
 *
 * You can easily play with the page transition by editing
 * these styles.
 */

.page-transition-enter {
  opacity: 0;
}

.page-transition-leave-active {
  opacity: 0;
}

.page-transition-enter .page-transition-container,
.page-transition-leave-active .page-transition-container {
  -webkit-transform: scale(1.1);
  transform: scale(1.1);
}

.container {
  flex: 1;
  display: flex;
  flex-direction: row;
  min-height: 87vh;
  max-width: 1500px;
  min-width: 1000px;
  margin: 0px auto;
}

@media only screen and (max-width: 1500px) {
  .container {
    max-width: 1350px;
  }
}

@media only screen and (max-width: 1400px) {
  .container {
    max-width: 1250px;
  }
}

@media only screen and (max-width: 1300px) {
  .container {
    max-width: 1150px;
  }
}
</style>
