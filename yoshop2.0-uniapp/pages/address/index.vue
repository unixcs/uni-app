<template>
  <view class="container" :style="appThemeStyle">
    <view class="addres-list">
      <view class="address-item" v-for="(item, index) in list" :key="index">
        <view class="contacts">
          <text class="name">{{ item.name }}</text>
          <text class="phone">{{ item.phone }}</text>
        </view>
        <view class="address">
          <text class="region" v-for="(region, idx) in item.region" :key="idx">{{ region }}</text>
          <text class="detail">{{ item.detail }}</text>
        </view>
        <view class="line"></view>
        <view class="item-option">
          <view class="_left">
            <view class="item-radio">
              <u-radio-group v-model="defaultId" @change="handleSetDefault(item.address_id)">
                <u-radio :name="item.address_id" :active-color="appTheme.mainBg">{{ item.address_id == defaultId ? '默认' : '选择' }}</u-radio>
              </u-radio-group>
            </view>
          </view>
          <view class="_right">
            <view class="events">
              <view class="event-item" @click="handleUpdate(item.address_id)">
                <text class="iconfont icon-edit"></text>
                <text class="title">编辑</text>
              </view>
              <view class="event-item" @click="handleRemove(item.address_id)">
                <text class="iconfont icon-delete"></text>
                <text class="title">删除</text>
              </view>
            </view>
          </view>
        </view>
      </view>
    </view>
    <empty v-if="!list.length" :isLoading="isLoading" tips="亲，暂无收货地址" />
    <!-- 底部操作按钮 -->
    <view class="footer-fixed">
      <view class="btn-wrapper">
        <view class="btn-item btn-item-main" @click="handleCreate()">添加新地址</view>
      </view>
    </view>
  </view>
</template>

<script>
  import * as AddressApi from '@/api/address'
  import Empty from '@/components/empty'

  export default {
    components: {
      Empty
    },
    data() {
      return {
        //当前页面参数
        options: {},
        // 正在加载
        isLoading: true,
        // 收货地址列表
        list: [],
        // 默认收货地址
        defaultId: null
      }
    },

    /**
     * 生命周期函数--监听页面加载
     */
    onLoad(options) {
      // 当前页面参数
      this.options = options
    },

    /**
     * 生命周期函数--监听页面显示
     */
    onShow() {
      // 获取页面数据
      this.getPageData()
    },

    methods: {

      // 获取页面数据
      getPageData() {
        const app = this
        app.isLoading = true
        Promise.all([app.getDefaultId(), app.getAddressList()])
          .then(() => {
            // 列表排序把默认收货地址放到最前
            app.onReorder()
          })
          .finally(() => app.isLoading = false)
      },

      // 获取收货地址列表
      getAddressList() {
        const app = this
        return new Promise((resolve, reject) => {
          AddressApi.list()
            .then(result => {
              app.list = result.data.list
              resolve(result)
            })
            .catch(reject)
        })
      },

      // 获取默认的收货地址
      getDefaultId() {
        return new Promise((resolve, reject) => {
          const app = this
          AddressApi.defaultId()
            .then(result => {
              app.defaultId = result.data.defaultId
              resolve(result)
            })
            .catch(reject)
        })
      },

      // 列表排序把默认收货地址放到最前
      onReorder() {
        const app = this
        app.list.sort(item => {
          return item.address_id == app.defaultId ? -1 : 1
        })
      },

      /**
       * 添加新地址
       */
      handleCreate() {
        this.$navTo('pages/address/create')
      },

      /**
       * 编辑地址
       * @param {int} addressId 收货地址ID
       */
      handleUpdate(addressId) {
        this.$navTo('pages/address/update', { addressId })
      },

      /**
       * 删除收货地址
       * @param {int} addressId 收货地址ID
       */
      handleRemove(addressId) {
        const app = this
        uni.showModal({
          title: "提示",
          content: "您确定要删除当前收货地址吗?",
          success({ confirm }) {
            confirm && app.onRemove(addressId)
          }
        });
      },

      /**
       * 确认删除收货地址
       * @param {int} addressId 收货地址ID
       */
      onRemove(addressId) {
        const app = this
        AddressApi.remove(addressId)
          .then(result => {
            app.getPageData()
          })
      },

      /**
       * 设置为默认地址
       * @param {Object} addressId
       */
      handleSetDefault(addressId) {
        const app = this
        AddressApi.setDefault(addressId)
          .then(result => {
            // app.defaultId = addressId
            app.options.from === 'checkout' && uni.navigateBack()
          })
      }
    }
  }
</script>

<style lang="scss" scoped>
  .addres-list {
    padding-top: 20rpx;
    // 设置ios刘海屏底部横线安全区域
    padding-bottom: calc(constant(safe-area-inset-bottom) + 140rpx);
    padding-bottom: calc(env(safe-area-inset-bottom) + 140rpx);
  }

  // 项目内容
  .address-item {
    margin: 0 auto 20rpx auto;
    padding: 30rpx 40rpx;
    width: 94%;
    box-shadow: 0 1rpx 5rpx 0 rgba(0, 0, 0, 0.05);
    border-radius: 16rpx;
    background: #fff;
  }

  .contacts {
    font-size: 30rpx;
    margin-bottom: 16rpx;

    .name {
      margin-right: 16rpx;
    }
  }

  .address {
    font-size: 28rpx;

    .region {
      margin-right: 10rpx;
    }
  }

  .line {
    margin: 20rpx 0;
    border-bottom: 1rpx solid #f3f3f3;
  }

  .item-option {
    display: flex;
    justify-content: space-between;
    height: 48rpx;

    // 单选框
    .item-radio {
      font-size: 28rpx;

      .radio {
        vertical-align: middle;
        transform: scale(0.76)
      }

      .text {
        vertical-align: middle;
      }
    }

    // 操作
    .events {
      display: flex;
      align-items: center;
      line-height: 48rpx;

      .event-item {
        font-size: 28rpx;
        margin-right: 26rpx;
        color: #4c4c4c;

        &:last-child {
          margin-right: 0;
        }

        .title {
          margin-left: 8rpx;
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
      height: 120rpx;
      padding: 0 40rpx;
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
      box-shadow: 0 1rpx 5rpx 0 rgba(0, 0, 0, 0.05);
    }

    .btn-item-main {
      background: linear-gradient(to right, $main-bg, $main-bg2);
      color: $main-text;
    }

  }
</style>