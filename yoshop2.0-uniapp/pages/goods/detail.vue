<template>
  <view v-show="!isLoading" class="container" :style="appThemeStyle">
    <!-- 商品图片轮播 -->
    <SlideImage v-if="!isLoading" :video="goods.video" :videoCover="goods.videoCover" :images="goods.goods_images" />

    <!-- 商品信息 -->
    <view v-if="!isLoading" class="goods-info m-top20">
      <!-- 价格、销量 -->
      <view class="info-item info-item__top dis-flex flex-x-between flex-y-end">
        <view class="block-left dis-flex flex-y-center">
          <!-- 商品售价 -->
          <text class="floor-price__samll">￥</text>
          <text class="floor-price">{{ goods.goods_price_min }}</text>
          <!-- 会员价标签 -->
          <view v-if="goods.is_user_grade" class="user-grade">
            <text>会员价</text>
          </view>
          <!-- 划线价 -->
          <text v-if="goods.line_price_min > 0" class="original-price">￥{{ goods.line_price_min }}</text>
        </view>
        <view class="block-right dis-flex">
          <!-- 销量 -->
          <view class="goods-sales">
            <text>已售{{ goods.goods_sales }}件</text>
          </view>
        </view>
      </view>
      <!-- 标题、分享 -->
      <view class="info-item info-item__name dis-flex flex-y-center">
        <view class="goods-name flex-box">
          <text class="twoline-hide">{{ goods.goods_name }}</text>
        </view>
        <view class="goods-share__line"></view>
        <view class="goods-share">
          <button class="share-btn dis-flex flex-dir-column" @click="onShowShareSheet()">
            <text class="share__icon iconfont icon-fenxiang"></text>
            <text class="f-24">分享</text>
          </button>
        </view>
      </view>
      <!-- 商品卖点 -->
      <view v-if="goods.selling_point" class="info-item info-item_selling-point">
        <text>{{ goods.selling_point }}</text>
      </view>
    </view>

    <!-- 选择商品规格 -->
    <view v-if="goods.spec_type == 20" class="goods-choice m-top20 b-f" @click="onShowSkuPopup(1)">
      <view class="spec-list">
        <view class="flex-box">
          <text class="col-8">选择：</text>
          <text class="spec-name" v-for="(item, index) in goods.specList" :key="index">{{ item.spec_name }}</text>
        </view>
        <view class="f-26 col-9 t-r">
          <text class="iconfont icon-arrow-right"></text>
        </view>
      </view>
    </view>

    <!-- 商品服务 -->
    <Service v-if="!isLoading" :goods-id="goodsId" />

    <!-- 商品SKU弹窗 -->
    <SkuPopup v-if="!isLoading" v-model="showSkuPopup" :skuMode="skuMode" :goods="goods" @addCart="onAddCart" />

    <!-- 商品评价 -->
    <Comment v-if="!isLoading" :goods-id="goodsId" :limit="2" />

    <!-- 商品描述 -->
    <view v-if="!isLoading" class="goods-content m-top20">
      <view class="item-title b-f">
        <text>商品描述</text>
      </view>
      <view v-if="goods.content != ''" class="goods-content__detail b-f">
        <mp-html :content="goods.content" />
      </view>
    </view>

    <!-- 底部选项卡 -->
    <view class="footer-fixed">
      <view class="footer-container">
        <!-- 导航图标 -->
        <view class="foo-item-fast">
          <!-- 首页 -->
          <view class="fast-item fast-item--home" @click="onTargetHome">
            <view class="fast-icon">
              <text class="iconfont icon-shouye"></text>
            </view>
            <view class="fast-text">
              <text>首页</text>
            </view>
          </view>
          <!-- 客服 -->
          <customer-btn v-if="isShowCustomerBtn" :showCard="true" :cardTitle="goods.goods_name" :cardImage="goods.goods_image"
            :cardPath="pagePath">
            <view class="fast-item">
              <view class="fast-icon">
                <text class="iconfont icon-kefu1"></text>
              </view>
              <view class="fast-text">
                <text>客服</text>
              </view>
            </view>
          </customer-btn>
          <!-- 购物车 -->
          <view class="fast-item fast-item--cart" @click="onTargetCart">
            <view v-if="cartTotal > 0" class="fast-badge fast-badge--fixed">{{ cartTotal > 99 ? '99+' : cartTotal }}
            </view>
            <view class="fast-icon">
              <text class="iconfont icon-gouwuche"></text>
            </view>
            <view class="fast-text">
              <text>购物车</text>
            </view>
          </view>
        </view>
        <!-- 操作按钮 -->
        <view class="foo-item-btn">
          <view class="btn-wrapper">
            <view v-if="isEnableCart" class="btn-item btn-item-deputy" @click="onShowSkuPopup(2)">
              <text>加入购物车</text>
            </view>
            <view class="btn-item btn-item-main" @click="onShowSkuPopup(3)">
              <text>立即购买</text>
            </view>
          </view>
        </view>
      </view>
    </view>

    <!-- 快捷导航 -->
    <!-- <shortcut bottom="120" /> -->

    <!-- 分享菜单 -->
    <share-sheet v-model="showShareSheet" :shareTitle="goods.goods_name" :shareImageUrl="goods.goods_image" />

  </view>
</template>

<script>
  import { getSceneData } from '@/core/app'
  import * as GoodsApi from '@/api/goods'
  import * as CartApi from '@/api/cart'
  import SettingModel from '@/common/model/Setting'
  import { GoodsTypeEnum } from '@/common/enum/goods'
  import ShareSheet from '@/components/share-sheet'
  import CustomerBtn from '@/components/customer-btn'
  import SlideImage from './components/SlideImage'
  import SkuPopup from './components/SkuPopup'
  import Comment from './components/Comment'
  import Service from './components/Service'

  export default {
    components: {
      ShareSheet,
      CustomerBtn,
      SlideImage,
      SkuPopup,
      Comment,
      Service
    },
    data() {
      return {
        // 正在加载
        isLoading: true,
        // 当前商品ID
        goodsId: null,
        // 商品详情
        goods: {},
        // 购物车总数量
        cartTotal: 0,
        // 显示/隐藏SKU弹窗
        showSkuPopup: false,
        // 模式 1:都显示 2:只显示购物车 3:只显示立即购买 4:显示缺货按钮 5:禁用 默认 1
        skuMode: 1,
        // 显示/隐藏分享菜单
        showShareSheet: false,
        // 是否支持加入购物车
        isEnableCart: false,
        // 是否显示在线客服按钮
        isShowCustomerBtn: false,
      }
    },
    computed: {
      // 当前页面链接
      pagePath() {
        const params = this.$getShareUrlParams({ goodsId: this.goodsId })
        return `/pages/goods/detail?${params}`
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    async onLoad(options) {
      // 记录query参数
      this.onRecordQuery(options)
      // 加载页面数据
      this.onRefreshPage()
      // 是否显示在线客服按钮
      this.isShowCustomerBtn = await SettingModel.isShowCustomerBtn()
    },

    methods: {

      // 记录query参数
      onRecordQuery(query) {
        const scene = getSceneData(query)
        this.goodsId = query.goodsId ? parseInt(query.goodsId) : parseInt(scene.gid)
      },

      // 刷新页面数据
      onRefreshPage() {
        const app = this
        app.isLoading = true
        Promise.all([app.getGoodsDetail(), app.getCartTotal()])
          .finally(() => app.isLoading = false)
      },

      // 获取商品信息
      getGoodsDetail() {
        const app = this
        return new Promise((resolve, reject) => {
          GoodsApi.detail(app.goodsId)
            .then(result => {
              app.goods = result.data.detail
              if (app.goods.goods_type == GoodsTypeEnum.PHYSICAL.value) {
                app.isEnableCart = true
              }
              resolve(result)
            })
            .catch(reject)
        })
      },

      // 获取购物车总数量
      getCartTotal() {
        const app = this
        return new Promise((resolve, reject) => {
          CartApi.total()
            .then(result => {
              app.cartTotal = result.data.cartTotal
              resolve(result)
            })
            .catch(reject)
        })
      },

      // 更新购物车数量
      onAddCart(total) {
        this.cartTotal = total
      },

      /**
       * 显示/隐藏SKU弹窗
       * @param {skuMode} 模式 1:都显示 2:只显示购物车 3:只显示立即购买
       */
      onShowSkuPopup(skuMode = 1) {
        const app = this
        if (app.isEnableCart) {
          app.skuMode = skuMode
        } else {
          app.skuMode = 3
        }
        app.showSkuPopup = !app.showSkuPopup
      },

      // 显示隐藏分享菜单
      onShowShareSheet() {
        this.showShareSheet = !this.showShareSheet
      },

      // 跳转到首页
      onTargetHome(e) {
        this.$navTo('pages/index/index')
      },

      // 跳转到购物车页
      onTargetCart() {
        this.$navTo('pages/cart/index')
      },

    },

    /**
     * 分享当前页面
     */
    onShareAppMessage() {
      return {
        title: this.goods.goods_name,
        path: this.pagePath
      }
    },

    /**
     * 分享到朋友圈
     * 本接口为 Beta 版本，暂只在 Android 平台支持，详见分享到朋友圈 (Beta)
     * https://developers.weixin.qq.com/miniprogram/dev/framework/open-ability/share-timeline.html
     */
    onShareTimeline() {
      return {
        title: this.goods.goods_name,
        path: this.pagePath
      }
    }
  }
</script>

<style>
  page {
    background: #fafafa;
  }
</style>
<style lang="scss" scoped>
  @import "./detail.scss";
</style>