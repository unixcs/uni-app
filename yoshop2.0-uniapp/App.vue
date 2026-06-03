<script>
  import store from '@/store'
  import StoreModel from '@/common/model/Store'
  import { getSceneData } from './core/app'
  import { isObject } from './utils/util'

  export default {

    /**
     * 全局变量
     */
    globalData: {},

    /**
     * 初始化完成时触发
     */
    onLaunch({ path, query, scene }) {
      // #ifdef MP
      // 小程序主动更新
      this.updateManager()
      // #endif
      // app启动参数
      this.onStartupQuery(isObject(query) ? query : {})
      // 获取商城基础信息
      this.getStoreInfo()
    },

    methods: {

      // app启动参数
      onStartupQuery(query) {
        // 获取二维码场景值
        // const scene = getSceneData(query)
      },

      // 获取商城基础信息
      getStoreInfo() {
        StoreModel.data(false).catch(err => {
          console.error('应用启动获取商城信息失败', err)
        })
      },

      // 小程序主动更新
      updateManager() {
        if (typeof uni.getUpdateManager !== 'function') {
          console.warn('当前环境不支持 getUpdateManager，跳过自动更新')
          return
        }
        const updateManager = uni.getUpdateManager()
        updateManager.onCheckForUpdate(res => {
          // 请求完新版本信息的回调
          // console.log(res.hasUpdate)
        })
        updateManager.onUpdateReady(() => {
          uni.showModal({
            title: '更新提示',
            content: '新版本已经准备好，即将重启应用',
            showCancel: false,
            success(res) {
              if (res.confirm) {
                // 新的版本已经下载好，调用 applyUpdate 应用新版本并重启
                updateManager.applyUpdate()
              }
            }
          })
        })
        updateManager.onUpdateFailed(() => {
          // 新的版本下载失败
          uni.showModal({
            title: '更新提示',
            content: '新版本下载失败',
            showCancel: false
          })
        })
      }

    }

  }
</script>

<style lang="scss">
  /* uView库样式 */
  @import "./uni_modules/vk-uview-ui/index.scss";
  /* 项目基础样式 */
  @import "./app.scss";
  /* iconfont图标库 */
  @import "./utils/iconfont.scss";
</style>
