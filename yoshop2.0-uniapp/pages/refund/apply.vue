<template>
  <view class="container" :style="appThemeStyle">
    <view v-if="!isLoading && loadError" class="load-error b-f">
      <text>{{ loadError }}</text>
      <view class="retry-btn" @click="getGoodsDetail()">重新加载</view>
    </view>
    <block v-if="!isLoading && !loadError">

    <!-- 商品详情 -->
    <view class="goods-detail b-f dis-flex flex-dir-row">
      <view class="left">
        <image class="goods-image" :src="goods.goods_image"></image>
      </view>
      <view class="right dis-flex flex-box flex-dir-column flex-x-around">
        <view class="goods-name">
          <text class="twoline-hide">{{ goods.goods_name }}</text>
        </view>
        <view class="dis-flex col-9 f-24">
          <view class="flex-box">
            <view class="goods-props clearfix">
              <view class="goods-props-item" v-for="(props, idx) in goods.goods_props" :key="idx">
                <text>{{ props.value.name }}</text>
              </view>
            </view>
          </view>
          <text class="t-r">×{{ goods.total_num }}</text>
        </view>
      </view>
    </view>

    <view v-if="isIosAppleRefundMode" class="row-guidance b-f m-top20">
      <view class="row-title">退款说明</view>
      <IosAppleRefundGuide />
    </view>

    <!-- 退款原因 -->
    <view class="row-textarea b-f m-top20">
      <view class="row-title">退款原因</view>
      <view class="content">
        <textarea class="textarea" v-model="formData.content" maxlength="2000" placeholder="请详细填写退款原因，建议您先与服务方沟通"
          placeholderStyle="color:#ccc"></textarea>
      </view>
    </view>

    <!-- 退款金额 -->
    <view class="row-money b-f m-top20 dis-flex">
      <view class="row-title">退款金额</view>
      <view class="money col-m">￥{{ goods.refund_money || goods.total_pay_price }}</view>
    </view>

    <!-- 上传凭证 -->
    <view v-if="!isIosAppleRefundMode" class="row-voucher b-f m-top20">
      <view class="row-title">上传凭证 (最多6张)</view>
      <view class="image-list">
        <!-- 图片列表 -->
        <view class="image-preview" v-for="(image, imageIndex) in imageList" :key="imageIndex">
          <text class="image-delete iconfont icon-shanchu" @click="deleteImage(imageIndex)"></text>
          <image class="image" mode="aspectFill" :src="image.path || image.tempFilePath"></image>
        </view>
        <!-- 上传图片 -->
        <view v-if="imageList.length < maxImageLength" class="image-picker" @click="chooseImage()">
          <text class="choose-icon iconfont icon-camera"></text>
          <text class="choose-text">上传图片</text>
        </view>
      </view>
    </view>

    <!-- 底部操作按钮 -->
    <view class="footer-fixed">
      <view class="btn-wrapper">
        <view class="btn-item btn-item-main" :class="{ disabled }" @click="handleSubmit()">{{ submitButtonText }}</view>
      </view>
    </view>

    </block>
  </view>
</template>

<script>
  import * as UploadApi from '@/api/upload'
  import * as RefundApi from '@/api/refund'
  import IosAppleRefundGuide from '@/components/refund/IosAppleRefundGuide.vue'

  const maxImageLength = 6

  export default {
    components: { IosAppleRefundGuide },
    data() {
      return {
        // 正在加载
        isLoading: true,
        loadError: '',
        // 订单商品id
        orderGoodsId: null,
        // 订单商品详情
        goods: {},
        // 表单数据
        formData: {
          // 图片上传成功的文件ID集
          images: [],
          // 退款类型
          type: 10,
          // 退款原因
          content: ''
        },
        // 用户选择的图片列表
        imageList: [],
        // 最大图片数量
        maxImageLength,
        // 按钮禁用
        disabled: false
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad({ orderGoodsId }) {
      this.orderGoodsId = orderGoodsId
      // 获取订单商品详情
      this.getGoodsDetail()
    },

    computed: {
      isIosAppleRefundMode() {
        return this.goods.ios_apple_refund_required === true || this.goods.ios_apple_refund_required === 1
      },
      refundGuidanceText() {
        return this.goods.refund_guidance || 'iOS 订单由 Apple 处理退款，商家无法直接原路退款。请访问 reportaproblem.apple.com 申请。'
      },
      submitButtonText() {
        return '提交退款申请'
      }
    },

    methods: {

      // 获取订单商品详情
      getGoodsDetail() {
        const app = this
        app.isLoading = true
        app.loadError = ''
        RefundApi.goods(app.orderGoodsId)
          .then(result => {
            const goods = result && result.data ? result.data.goods : null
            if (!goods || !goods.order_goods_id) throw new Error('退款信息数据无效')
            app.goods = goods
          })
          .catch(() => {
            app.goods = {}
            app.loadError = '退款信息加载失败，请稍后重试'
          })
          .finally(() => { app.isLoading = false })
      },

      // 选择图片
      chooseImage() {
        const app = this
        const oldImageList = app.imageList
        // 选择图片
        // #ifndef MP-WEIXIN
        uni.chooseImage({
          count: maxImageLength - oldImageList.length,
          sizeType: ['original', 'compressed'], // 可以指定是原图还是压缩图，默认二者都有
          sourceType: ['album', 'camera'], // 可以指定来源是相册还是相机，默认二者都有
          success({ tempFiles }) {
            // tempFiles = [{path:'xxx', size:100}]
            app.imageList = oldImageList.concat(tempFiles)
          },
          fail(err) {
            console.log('chooseImage fail', err)
          }
        })
        // #endif
        // #ifdef MP-WEIXIN
        uni.chooseMedia({
          count: maxImageLength - oldImageList.length,
          mediaType: ['image'],
          sourceType: ['album', 'camera'],
          sizeType: ['original', 'compressed'],
          success({ tempFiles }) {
            // tempFiles = [{tempFilePath:'xxx', size:100}]
            app.imageList = oldImageList.concat(tempFiles)
          },
          fail(err) {
            console.log('chooseMedia fail', err)
          }
        })
        // #endif
      },

      // 删除图片
      deleteImage(imageIndex) {
        this.imageList.splice(imageIndex, 1)
      },

      // 表单提交
      handleSubmit() {
        const app = this
        if (app.loadError || !app.goods.order_goods_id) {
          app.$toast('退款信息尚未加载完成，请重新加载')
          return false
        }
        if (app.isIosAppleRefundMode) {
          app.imageList = []
          app.formData.images = []
        }
        const imageList = app.isIosAppleRefundMode ? [] : app.imageList
        // 判断是否重复提交
        if (app.disabled === true) return false
        // 表单验证
        if (!app.formData.content.trim().length) {
          app.$toast(app.isIosAppleRefundMode ? '请填写退款说明，便于售后跟进' : '请填写退款原因')
          return false
        }
        // 按钮禁用
        app.disabled = true
        // 判断是否需要上传图片
        if (imageList.length > 0) {
          app.uploadFile()
            .then(() => app.onSubmit())
            .catch(err => {
              app.disabled = false
              if (err.statusCode !== 0) {
                app.$toast(err.errMsg)
              }
              console.log('err', err)
            })
        } else {
          app.onSubmit()
        }
      },

      // 提交到后端
      onSubmit() {
        const app = this
        const payload = { ...app.formData }
        if (app.isIosAppleRefundMode) delete payload.images
        RefundApi.apply(app.orderGoodsId, payload)
          .then(result => {
            app.$toast(result.message)
            setTimeout(() => {
              app.disabled = false
              uni.navigateBack()
            }, 1500)
          })
          .catch(err => app.disabled = false)
      },

      // 上传图片
      uploadFile() {
        const app = this
        const { imageList } = app
        // 批量上传
        return new Promise((resolve, reject) => {
          if (imageList.length > 0) {
            UploadApi.image(imageList)
              .then(fileIds => {
                app.formData.images = fileIds
                resolve(fileIds)
              })
              .catch(reject)
          } else {
            resolve()
          }
        })
      }

    }
  }
</script>

<style lang="scss" scoped>
  .container {
    // 设置ios刘海屏底部横线安全区域
    padding-bottom: calc(constant(safe-area-inset-bottom) + 180rpx);
    padding-bottom: calc(env(safe-area-inset-bottom) + 180rpx);
  }

  .row-title {
    color: #888;
    margin-bottom: 20rpx;
  }

  .load-error {
    margin: 30rpx 20rpx;
    padding: 40rpx 20rpx;
    text-align: center;
    color: #666;

    .retry-btn { display: inline-block; margin-top: 24rpx; padding: 12rpx 30rpx; border: 1rpx solid #c1c1c1; border-radius: 28rpx; color: #333; }
  }

  .row-guidance {
    padding: 24rpx 20rpx;

    .guidance-text {
      font-size: 28rpx;
      line-height: 1.6;
      color: #333;
    }

    .guidance-subtext {
      display: block;
      margin-top: 16rpx;
      font-size: 24rpx;
      line-height: 1.6;
      color: #999;
    }
  }

  // 商品信息
  .goods-detail {
    padding: 24rpx 20rpx;

    .left {
      .goods-image {
        display: block;
        width: 150rpx;
        height: 150rpx;
      }
    }

    .right {
      padding-left: 20rpx;
    }

    .goods-props {
      margin-top: 14rpx;
      color: #ababab;
      font-size: 24rpx;
      overflow: hidden;

      .goods-props-item {
        padding: 4rpx 16rpx;
        border-radius: 12rpx;
        background-color: #fcfcfc;
      }
    }
  }

  /* 服务类型 */
  .row-service {
    padding: 24rpx 20rpx;
  }

  .service-switch {
    .switch-item {
      padding: 6rpx 30rpx;
      margin-right: 25rpx;
      border-radius: 10rpx;
      border: 1px solid rgb(177, 177, 177);
      color: #888888;

      &.active {
        color: $main-bg;
        border: 1px solid $main-bg;
      }
    }
  }

  /* 申请原因 */
  .row-textarea {
    padding: 24rpx 20rpx;

    .textarea {
      width: 100%;
      height: 220rpx;
      padding: 12rpx;
      border: 1rpx solid #e8e8e8;
      border-radius: 5rpx;
      box-sizing: border-box;
      font-size: 26rpx;
    }
  }

  /* 退款金额 */
  .row-money {
    padding: 24rpx 20rpx;

    .row-title {
      margin-bottom: 0;
      margin-right: 30rpx;
    }
  }

  // 上传凭证
  .row-voucher {
    padding: 24rpx 20rpx;

    .image-list {
      padding: 0 20rpx;
      margin-top: 20rpx;
      margin-bottom: -20rpx;

      &:after {
        clear: both;
        content: " ";
        display: table;
      }

      .image {
        display: block;
        width: 100%;
        height: 100%;
      }

      .image-picker,
      .image-preview {
        width: 184rpx;
        height: 184rpx;
        margin-right: 30rpx;
        margin-bottom: 30rpx;
        float: left;

        &:nth-child(3n+0) {
          margin-right: 0;
        }
      }

      .image-picker {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        border: 1rpx dashed #ccc;
        color: #ccc;

        .choose-icon {
          font-size: 48rpx;
          margin-bottom: 6rpx;
        }

        .choose-text {
          font-size: 24rpx;
        }
      }

      .image-preview {
        position: relative;

        .image-delete {
          position: absolute;
          top: -15rpx;
          right: -15rpx;
          height: 42rpx;
          width: 42rpx;
          background: rgba(0, 0, 0, 0.64);
          border-radius: 50%;
          color: #fff;
          font-weight: bolder;
          font-size: 22rpx;
          z-index: 10;
          display: flex;
          justify-content: center;
          align-items: center;
        }
      }
    }
  }

  // 底部操作栏
  .footer-fixed {
    position: fixed;
    bottom: var(--window-bottom);
    left: var(--window-left);
    right: var(--window-right);
    z-index: 11;

    // 设置ios刘海屏底部横线安全区域
    padding-bottom: constant(safe-area-inset-bottom);
    padding-bottom: env(safe-area-inset-bottom);

    .btn-wrapper {
      height: 140rpx;
      display: flex;
      align-items: center;
      padding: 0 20rpx;
    }

    .btn-item {
      flex: 1;
      font-size: 28rpx;
      height: 80rpx;
      color: #fff;
      border-radius: 50rpx;
      display: flex;
      justify-content: center;
      align-items: center;
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
