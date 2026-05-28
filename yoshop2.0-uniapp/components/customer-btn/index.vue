<template>
  <view v-if="isShow">
    <!-- 微信小程序端、H5端、APP端 -->
    <!-- #ifdef MP-WEIXIN || H5 || APP-PLUS -->
    <button class="btn-normal" :open-type="setting.provider == 'mpwxkf' ? 'contact' : ''" :show-message-card="showCard"
      :send-message-path="cardPath" :send-message-title="cardTitle" :send-message-img="cardImage" @click="handleContact()">
      <slot></slot>
    </button>
    <!-- #endif -->
  </view>
</template>

<script>
  import SettingKeyEnum from '@/common/enum/setting/Key'
  import SettingModel from '@/common/model/Setting'

  export default {
    props: {
      // 是否显示消息卡片
      showCard: {
        Type: Boolean,
        default: false
      },
      // 消息卡片标题
      cardTitle: {
        Type: String,
        default: ''
      },
      // 消息卡片图片
      cardImage: {
        Type: String,
        default: ''
      },
      // 消息卡片点击跳转的路径
      cardPath: {
        Type: String,
        default: ''
      },
    },
    data() {
      return {
        isShow: false,
        setting: {}
      }
    },
    async created() {
      // 是否显示在线客服按钮
      this.isShow = await SettingModel.isShowCustomerBtn()
      // 商城客服设置
      this.setting = await SettingModel.item(SettingKeyEnum.CUSTOMER.value, true)
    },
    methods: {

    }
  }
</script>

<style lang="scss" scoped>

</style>