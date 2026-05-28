<template>
  <view v-if="isLoad" class="login" :style="appThemeStyle">
    <MpWeixin v-if="isMpWeixinAuth" @success="onGetUserInfoSuccess" />
    <Main v-else :isParty="isParty" :partyData="partyData" :isMpWeixinMobile="isMpWeixinMobile" />
  </view>
</template>

<script>
  import Main from './components/main'
  import MpWeixin from './components/mp-weixin'
  import SettingKeyEnum from '@/common/enum/setting/Key'
  import SettingModel from '@/common/model/Setting'

  export default {
    components: {
      Main,
      MpWeixin
    },

    data() {
      return {
        // 数据加载完成 [防止在微信小程序端onLoad和view渲染同步进行]
        isLoad: false,
        // 注册设置 (后台设置)
        setting: {},
        // 是否显示微信小程序授权登录
        isMpWeixinAuth: false,
        // 是否显示微信小程序端 一键授权手机号
        isMpWeixinMobile: false,
        // 是否存在第三方用户信息
        isParty: false,
        // 第三方用户信息数据
        partyData: {}
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    async onLoad(options) {
      // 获取注册设置
      await this.getRegisterSetting()
      // 设置当前是否显示第三方授权登录
      await this.setShowUserInfo()
      // 数据加载完成
      this.isLoad = true
    },

    methods: {

      // 获取注册设置 [后台-客户端-注册设置]
      async getRegisterSetting() {
        this.setting = await SettingModel.item(SettingKeyEnum.REGISTER.value, false)
      },

      /**
       * 设置当前是否显示第三方授权登录
       *  - 条件1: 只有对应的客户端显示获取用户信息按钮, 例如微信小程序、微信公众号
       *  - 条件2: 注册设置是否已开启该选项
       */
      async setShowUserInfo() {
        const app = this
        // 判断当前客户端是微信小程序
        const isMpWeixin = app.platform === 'MP-WEIXIN'
        // 判断是否显示第三方授权登录
        app.isMpWeixinAuth = isMpWeixin && Boolean(app.setting.isOauthMpweixin)
        app.isMpWeixinMobile = isMpWeixin && Boolean(app.setting.isOauthMobileMpweixin)
      },

      // 获取到用户信息的回调函数
      onGetUserInfoSuccess(result) {
        // 记录第三方用户信息数据
        this.partyData = result
        // 显示注册页面
        this.onShowRegister()
      },

      // 显示注册页面
      onShowRegister() {
        // 是否显示微信小程序授权登录
        if (this.partyData.oauth === 'MP-WEIXIN') {
          this.isMpWeixinAuth = false
        }
        // 已获取到了第三方用户信息
        this.isParty = true
      }
    }
  }
</script>

<style lang="scss" scoped>

</style>