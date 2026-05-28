<template>
  <view class="container">
    <view v-if="isPersonal === true" class="personal">
      <!-- 页面头部 -->
      <view class="header">
        <view class="title">
          <text>获取您的昵称、头像</text>
        </view>
        <view class="sub-title">
          <text>填写您的微信头像和昵称，以便获得更好的体验</text>
        </view>
      </view>
      <!-- 表单组件 -->
      <view class="login-form">
        <view class="form-item">
          <view class="form-item--label">头像</view>
          <button class="btn-normal" open-type="chooseAvatar" @click="onClickAvatar()" @chooseavatar="onChooseAvatar">
            <avatar-image :url="avatarUrl" :width="100" />
          </button>
        </view>
        <view class="form-item">
          <view class="form-item--label">昵称</view>
          <input class="form-item--input" type="nickname" v-model="form.nickName" maxlength="30" placeholder="请输入微信昵称"
            @input="onInputNickName" @blur="onInputNickName" />
        </view>
      </view>
      <!-- 操作按钮 -->
      <view class="login-btn">
        <view class="button" :class="{ disabled }" @click="handleSubmit()">确认</view>
      </view>
      <view class="no-login-btn">
        <view class="button" @click="handleCancel()">暂不登录</view>
      </view>
    </view>
    <view v-if="isPersonal === false" class="authorize">
      <view class="store-info">
        <view class="header">
          <image class="image" :src="storeInfo && storeInfo.image_url ? storeInfo.image_url : '/static/default-logo.png'"></image>
        </view>
      </view>
      <view class="auth-title">申请获取以下权限</view>
      <view class="auth-subtitle">获得你的公开信息（昵称、头像等）</view>
      <view class="login-btn">
        <view class="button" :class="{ disabled }" @click="handleLogin()">一键登录</view>
      </view>
      <view class="no-login-btn">
        <view class="button" @click="handleCancel()">暂不登录</view>
      </view>
    </view>
  </view>
</template>

<script>
  import store from '@/store'
  import * as LoginApi from '@/api/login'
  import * as UploadApi from '@/api/upload'
  import AvatarImage from '@/components/avatar-image'
  import { isEmpty } from '@/utils/util'
  import * as Verify from '@/utils/verify'
  import StoreModel from '@/common/model/Store'

  export default {
    components: {
      AvatarImage
    },
    data() {
      return {
        // 商城基本信息
        storeInfo: undefined,
        // 是否需要填写用户昵称和头像
        isPersonal: undefined,
        // 微信小程序登录凭证 (code)
        // 提交到后端，用于换取openid
        code: '',
        // 按钮禁用
        disabled: false,
        // 头像路径 (用于显示)
        avatarUrl: '',
        // 临时图片 (用于上传)
        tempFile: undefined,
        // 表单数据
        form: {
          avatarId: null,
          nickName: ''
        },
      }
    },

    created() {
      // 获取商城基本信息
      this.getStoreInfo()
      // 请求后端是否需要填写昵称头像 (微信小程序端)
      this.getIsPersonal()
    },

    methods: {

      // 获取商城基本信息
      getStoreInfo() {
        StoreModel.storeInfo().then(storeInfo => this.storeInfo = storeInfo)
      },

      /**
       * 请求后端是否需要填写昵称头像 (微信小程序端)
       *  - 条件1: 后台开启了填写微信头像和昵称
       *  - 条件2: 用户首次注册或者已注册但未填写过信息
       */
      async getIsPersonal() {
        const app = this
        LoginApi.isPersonalMpweixin({ code: await app.getCode() })
          .then(result => app.isPersonal = result.data.isPersonalMpweixin)
      },

      // 获取code
      // https://developers.weixin.qq.com/miniprogram/dev/api/open-api/login/wx.login.html
      getCode() {
        return new Promise((resolve, reject) => {
          uni.login({
            provider: 'weixin',
            success({ code }) {
              console.log('code', code)
              resolve(code)
            },
            fail: reject
          })
        })
      },

      // 绑定昵称输入框 (用于微信小程序端快速填写昵称能力)
      onInputNickName({ detail }) {
        console.log(detail)
        if (detail.value) {
          this.form.nickName = detail.value
        }
      },

      // 点击头像按钮事件
      onClickAvatar() {
        // #ifdef MP-WEIXIN
        return
        // #endif
        this.chooseImage()
      },

      // 选择头像事件 - 仅限微信小程序
      onChooseAvatar({ detail }) {
        // #ifdef MP-WEIXIN
        const app = this
        app.avatarUrl = detail.avatarUrl
        app.tempFile = { path: app.avatarUrl }
        // #endif
      },

      // 选择图片
      chooseImage() {
        const app = this
        // 选择图片
        uni.chooseImage({
          count: 1,
          sizeType: ['original', 'compressed'], // 可以指定是原图还是压缩图，默认二者都有
          sourceType: ['album', 'camera'], // 可以指定来源是相册还是相机，默认二者都有
          success({ tempFiles }) {
            app.tempFile = tempFiles[0]
            app.avatarUrl = app.tempFile.path
          }
        });
      },

      // 上传图片
      uploadFile() {
        const app = this
        return UploadApi.image([app.tempFile], false)
          .then(fileIds => {
            app.form.avatarId = fileIds[0]
            app.tempFile = null
            return true
          })
          .catch(() => {
            return false
          })
      },

      // 确认提交头像昵称
      async handleSubmit() {
        const app = this
        console.log('handleSubmit', app.form, app.tempFile)
        if (app.disabled === true) {
          return
        }
        if (app.tempFile === undefined || Verify.isEmpty(app.form.nickName)) {
          app.$toast('请填写头像和昵称~')
          return
        }
        // 按钮禁用
        app.disabled = true
        // 先上传头像图片
        if (!await app.uploadFile()) {
          app.$toast('很抱歉，头像上传失败')
          app.disabled = false
          return
        }
        // 提交到后端
        app.onAuthSuccess({ nickName: app.form.nickName, avatarId: app.form.avatarId })
      },

      // 一键登录按钮点击事件
      handleLogin() {
        // this.onAuthSuccess({ nickName: '微信用户', avatarUrl: '' })
        this.onAuthSuccess({})
      },

      // 授权成功事件
      // 这里分为两个逻辑:
      // 1.将code和userInfo提交到后端，如果存在该用户 则实现自动登录，无需再填写手机号
      // 2.如果不存在该用户, 则显示注册页面, 需填写手机号
      // 3.如果后端报错了, 则显示错误信息
      async onAuthSuccess(userInfo) {
        const app = this
        // 提交到后端
        store.dispatch('LoginMpWx', {
            partyData: {
              code: await app.getCode(),
              oauth: 'MP-WEIXIN',
              userInfo
            }
          })
          .then(result => {
            // 一键登录成功
            app.$toast(result.message)
            // 相应全局事件订阅: 刷新上级页面数据
            uni.$emit('syncRefresh', true)
            // 跳转回原页面
            setTimeout(() => app.onNavigateBack(), 2000)
          })
          .catch(err => {
            const resultData = err.result.data
            // 判断还需绑定手机号
            if (resultData.isBindMobile) {
              app.onEmitSuccess(userInfo)
            }
          })
      },

      // 将oauth提交给父级
      // 这里要重新获取code, 因为上一次获取的code不能复用(会报错)
      async onEmitSuccess(userInfo) {
        const app = this
        app.$emit('success', {
          oauth: 'MP-WEIXIN', // 第三方登录类型: MP-WEIXIN
          code: await app.getCode(), // 微信登录的code, 用于换取openid
          userInfo // 微信用户信息
        })
      },

      /**
       * 暂不登录
       */
      handleCancel() {
        // 跳转回原页面
        this.onNavigateBack()
      },

      /**
       * 登录成功-跳转回原页面
       */
      onNavigateBack(delta = 1) {
        const pages = getCurrentPages()
        if (pages.length > 1) {
          uni.navigateBack({
            delta: Number(delta || 1)
          })
        } else {
          this.$navTo('pages/index/index')
        }
      }

    }
  }
</script>

<style lang="scss" scoped>
  .container {
    padding: 0 60rpx;
    font-size: 32rpx;
    background: #fff;
    min-height: 100vh;
  }

  .personal {

    // 页面头部
    .header {
      padding-top: 120rpx;
      margin-bottom: 60rpx;

      .title {
        margin-bottom: 20rpx;
        color: #191919;
        font-size: 44rpx;
      }

      .sub-title {
        margin-bottom: 70rpx;
        color: #b3b3b3;
        font-size: 28rpx;
      }
    }

    .login-form {
      margin-bottom: 90rpx;
    }

    // 输入框元素
    .form-item {
      display: flex;
      align-items: center;
      padding: 18rpx;
      border-bottom: 1rpx solid #f3f1f2;
      margin-bottom: 30rpx;
      min-height: 96rpx;

      &--label {
        min-width: 150rpx;
        font-size: 28rpx;
        line-height: 50rpx;
      }

      &--input {
        font-size: 28rpx;
        letter-spacing: 1rpx;
        flex: 1;
        height: 100%;
      }

      &--parts {
        min-width: 100rpx;
      }

    }

  }

  .authorize {
    .store-info {
      padding: 80rpx 0 48rpx;
      border-bottom: 1rpx solid #e3e3e3;
      margin-bottom: 72rpx;
      text-align: center;

      .header {
        width: 190rpx;
        height: 190rpx;
        border: 4rpx solid #fff;
        margin: 0 auto 0;
        border-radius: 50%;
        overflow: hidden;
        box-shadow: 2rpx 0 10rpx rgba(50, 50, 50, 0.3);

        .image {
          display: block;
          width: 100%;
          height: 100%;
        }
      }
    }

    .auth-title {
      color: #585858;
      font-size: 34rpx;
      margin-bottom: 40rpx;
    }

    .auth-subtitle {
      color: #888;
      margin-bottom: 88rpx;
      font-size: 28rpx;
    }

  }

  .login-btn {
    margin-bottom: 20rpx;

    .button {
      width: 100%;
      height: 86rpx;
      background: linear-gradient(to right, $main-bg, $main-bg2);
      color: $main-text;
      font-size: 30rpx;
      border-radius: 80rpx;
      letter-spacing: 5rpx;
      display: flex;
      justify-content: center;
      align-items: center;

      // 禁用按钮
      &.disabled {
        opacity: 0.6;
      }
    }
  }


  .no-login-btn {

    .button {
      height: 86rpx;
      background: #dfdfdf;
      color: #fff;
      font-size: 30rpx;
      border-radius: 80rpx;
      display: flex;
      justify-content: center;
      align-items: center;
    }
  }
</style>