<template>
  <!-- 在线客服 -->
  <view v-if="isShow" class="diy-service" :style="{ '--right': `${itemStyle.right * 2}rpx`, '--bottom': `${itemStyle.bottom * 2}rpx` }">
    <!-- 拨打电话 -->
    <block v-if="params.type === 'phone'">
      <view class="service-icon" @click="onMakePhoneCall">
        <image class="image" :src="params.image"></image>
      </view>
    </block>
    <!-- 在线聊天 -->
    <block v-else-if="params.type === 'chat'">
      <customer-btn>
        <view class="service-icon">
          <image class="image" :src="params.image"></image>
        </view>
      </customer-btn>
    </block>
  </view>
</template>

<script>
  import CustomerBtn from '@/components/customer-btn'
  // import { rpx2px } from '@/utils/util'
  import SettingModel from '@/common/model/Setting'

  export default {
    components: {
      CustomerBtn
    },
    props: {
      itemStyle: Object,
      params: Object
    },
    data() {
      return {
        isShow: false
      }
    },
    // computed: {
    //   right() {
    //     return rpx2px(2 * this.itemStyle.right)
    //   },
    //   bottom() {
    //     return rpx2px(2 * this.itemStyle.bottom)
    //   }
    // },
    async created() {
      if (this.params.type === 'phone') {
        this.isShow = true
      }
      if (this.params.type === 'chat') {
        this.isShow = await SettingModel.isShowCustomerBtn()
      }
    },
    methods: {

      /**
       * 点击拨打电话
       */
      onMakePhoneCall(e) {
        uni.makePhoneCall({
          phoneNumber: this.params.tel
        })
      }

    }

  }
</script>

<style lang="scss" scoped>
  .diy-service {
    position: fixed;
    z-index: 999;
    right: calc(var(--window-right) + var(--right));
    // 设置ios刘海屏底部横线安全区域
    bottom: calc(constant(safe-area-inset-bottom) + var(--window-bottom) + var(--bottom));
    bottom: calc(env(safe-area-inset-bottom) + var(--window-bottom) + var(--bottom));

    .service-icon {
      padding: 10rpx;

      .image {
        display: block;
        width: 90rpx;
        height: 90rpx;
        border-radius: 50%;
        box-shadow: 0 4rpx 20rpx rgba(0, 0, 0, 0.1);
      }
    }

  }
</style>