<template>
  <view class="container" :style="appThemeStyle">
    <!-- 标题 -->
    <view class="page-title">收货地址</view>
    <!-- 表单组件 -->
    <view class="form-wrapper">
      <u-form :model="form" ref="uForm" label-width="140rpx">
        <u-form-item label="姓名" prop="name">
          <u-input v-model="form.name" placeholder="请输入收货人姓名" />
        </u-form-item>
        <u-form-item label="电话" prop="phone">
          <u-input v-model="form.phone" placeholder="请输入收货人手机号" />
        </u-form-item>
        <u-form-item label="地区" prop="region">
          <select-region ref="sRegion" v-model="form.region" />
        </u-form-item>
        <u-form-item label="详细地址" prop="detail" :border-bottom="false">
          <u-input v-model="form.detail" placeholder="街道门牌、楼层等信息" />
        </u-form-item>
      </u-form>
    </view>
    <!-- 操作按钮 -->
    <view class="footer">
      <view class="btn-wrapper">
        <!-- #ifdef MP-WEIXIN -->
        <view class="btn-item btn-item-wechat" @click="chooseAddress()">选择微信收货地址</view>
        <!-- #endif -->
        <view class="btn-item btn-item-main" :class="{ disabled }" @click="handleSubmit()">保存</view>
      </view>
    </view>
  </view>
</template>

<script>
  import SelectRegion from '@/components/select-region/select-region'
  import { isMobile } from '@/utils/verify'
  import * as AddressApi from '@/api/address'

  // 表单验证规则
  const rules = {
    name: [{
      required: true,
      message: '请输入姓名',
      trigger: ['blur', 'change']
    }],
    phone: [{
      required: true,
      message: '请输入手机号',
      trigger: ['blur', 'change']
    }, {
      // 自定义验证函数
      validator: (rule, value, callback) => {
        // 返回true表示校验通过，返回false表示不通过
        return isMobile(value)
      },
      message: '手机号码不正确',
      // 触发器可以同时用blur和change
      trigger: ['blur'],
    }],
    region: [{
      required: true,
      message: '请选择省市区',
      trigger: ['blur', 'change'],
      type: 'array'
    }],
    detail: [{
      required: true,
      message: '请输入详细地址',
      trigger: ['blur', 'change']
    }],
  }

  export default {
    components: {
      SelectRegion
    },
    data() {
      return {
        form: {
          content: '',
          name: '',
          phone: '',
          region: [],
          detail: ''
        },
        rules,
        // 按钮禁用
        disabled: false
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad(options) {},

    // 必须要在onReady生命周期，因为onLoad生命周期组件可能尚未创建完毕
    onReady() {
      this.$refs.uForm.setRules(this.rules)
    },

    methods: {

      // 选择微信地址
      // #ifdef MP-WEIXIN
      chooseAddress() {
        const app = this
        const { form, $refs } = this
        uni.chooseAddress({
          success(res) {
            const names = $refs.sRegion.getOptionItemByNames(res)
            form.name = res.userName
            form.phone = res.telNumber
            form.detail = res.detailInfo
            form.region = names.length > 0 ? names : []
          },
          fail({ errMsg }) {
            if (errMsg != 'chooseAddress:fail cancel') {
              app.$toast(errMsg)
            }
            console.error('获取微信地址失败：', errMsg)
          }
        })
      },
      // #endif

      // 生成默认的表单数据
      createFormData(detail) {
        const { form } = this
        form.name = detail.name
        form.phone = detail.phone
        form.detail = detail.detail
        form.region = this.createRegion(detail)
      },

      // 格式化地区数据 (用于select-region组件)
      createRegion(detail) {
        if (detail.province_id == 0 || detail.city_id == 0 || detail.region_id == 0) {
          this.$toast('很抱歉，地区未能识别请手动选择', 2000)
          return []
        }
        return [{
          label: detail.region.province,
          value: detail.province_id
        }, {
          label: detail.region.city,
          value: detail.city_id
        }, {
          label: detail.region.region,
          value: detail.region_id
        }]
      },

      // 表单提交
      handleSubmit() {
        const app = this
        if (app.disabled) {
          return false
        }
        app.$refs.uForm.validate(valid => {
          if (valid) {
            app.disabled = true
            AddressApi.add(app.form)
              .then(result => {
                app.$toast(result.message)
                uni.navigateBack()
              })
              .finally(() => app.disabled = false)
          }
        })
      }

    }
  }
</script>

<style>
  page {
    background: #f7f8fa;
  }
</style>
<style lang="scss" scoped>
  .page-title {
    width: 94%;
    margin: 0 auto;
    padding-top: 40rpx;
    font-size: 28rpx;
    color: rgba(69, 90, 100, 0.6);
  }

  .form-wrapper {
    margin: 20rpx auto 20rpx auto;
    padding: 0 40rpx;
    width: 94%;
    box-shadow: 0 1rpx 5rpx 0px rgba(0, 0, 0, 0.05);
    border-radius: 16rpx;
    background: #fff;
  }

  // 底部操作栏
  .footer {
    margin-top: 80rpx;

    .btn-wrapper {
      height: 100%;
      // display: flex;
      // align-items: center;
      padding: 0 20rpx;
    }

    .btn-item {
      flex: 1;
      font-size: 28rpx;
      height: 86rpx;
      color: #fff;
      border-radius: 50rpx;
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .btn-item-wechat {
      background: #0ba90b;
      margin-bottom: 26rpx;
    }

    .btn-item-main {
      background: linear-gradient(to right, $main-bg, $main-bg2);
      color: $main-text;

      // 禁用按钮
      &.disabled {
        opacity: 0.6;
      }
    }

  }
</style>