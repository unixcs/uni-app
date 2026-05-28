<template>
  <view class="container" :style="appThemeStyle">
    <!-- 一级分类 -->
    <scroll-view class="cate-left" :scroll-y="true" @touchmove.stop.prevent>
      <text class="type-nav" :class="{ selected: curIndex == -1 }" @click="handleSelectNav(-1)">全部</text>
      <text class="type-nav" :class="{ selected: curIndex == index }" v-for="(item, index) in list" :key="index"
        @click="handleSelectNav(index)">{{ item.name }}</text>
    </scroll-view>

    <mescroll-body ref="mescrollRef" :sticky="true" @init="mescrollInit" :down="{ use: false }" :up="upOption" :bottombar="false"
      @up="upCallback">

      <view class="cate-content">

        <!-- 子分类 -->
        <view v-if="subCateList.length" class="sub-cate-list clearfix" :class="{ 'display-fold': !showSubCate }" @touchmove.stop.prevent>
          <view class="nav-icon" @click="handleShowSubCate">
            <text class="iconfont" :class="[ showSubCate ? 'icon-arrow-up' : 'icon-arrow-down' ]"></text>
          </view>
          <view class="sub-cate-item" :class="{ selected: curIndex2 == -1 }" @click="handleSelectSubCate(-1)">
            <text>全部</text>
          </view>
          <view class="sub-cate-item" v-for="(item, index) in subCateList" :key="index" :class="{ selected: curIndex2 == index }"
            @click="handleSelectSubCate(index)">
            <text>{{ item.name }}</text>
          </view>
        </view>

        <!-- 商品列表 -->
        <view class="goods-list">
          <view class="goods-item--container" v-for="(item, index) in goodsList.data" :key="index">
            <view class="goods-item" @click="onTargetGoods(item.goods_id)">
              <!-- 商品图片 -->
              <view class="goods-item-left">
                <image class="image" :src="item.goods_image"></image>
              </view>
              <view class="goods-item-right">
                <!-- 商品标题 -->
                <view class="goods-name">
                  <text class="twoline-hide">{{ item.goods_name }}</text>
                </view>
                <!-- 商品信息 -->
                <view class="goods-item-desc">
                  <view class="desc-footer">
                    <view class="item-prices oneline-hide">
                      <text class="price-x">¥{{ item.goods_price_min }}</text>
                      <text v-if="item.line_price_min > 0" class="price-y">¥{{ item.line_price_min }}</text>
                    </view>
                    <add-cart-btn v-if="setting.showAddCart" :btnStyle="setting.cartStyle" @handleAddCart="handleAddCart(item)" />
                  </view>
                </view>
              </view>
            </view>
          </view>
        </view>
        <!-- 遮罩层 -->
        <view class="mask" v-show="showSubCate" @touchmove.stop.prevent @click="handleShowSubCate"></view>
        <!-- 加入购物车组件 -->
        <AddCartPopup ref="AddCartPopup" @addCart="onUpdateCartTabBadge" />

      </view>
    </mescroll-body>
  </view>
</template>

<script>
  import MescrollMixin from '@/uni_modules/mescroll-uni/components/mescroll-uni/mescroll-mixins'
  import { getEmptyPaginateObj, getMoreListData, setCartTabBadge } from '@/core/app'
  import AddCartBtn from '@/components/add-cart-btn'
  import AddCartPopup from '@/components/add-cart-popup'
  import * as GoodsApi from '@/api/goods'

  const pageSize = 15

  export default {
    components: {
      AddCartBtn,
      AddCartPopup
    },
    mixins: [MescrollMixin],
    props: {
      // 分类列表
      list: {
        type: Array,
        default: []
      },
      // 分类设置
      setting: {
        type: Object,
        default: () => {}
      },
      // query参数
      query: {
        type: Object,
        default: () => {}
      },
    },
    data() {
      return {
        // 一级分类：指针
        curIndex: -1,
        // 是否显示子分类
        showSubCate: false,
        // 二级分类：指针
        curIndex2: -1,
        // 商品列表
        goodsList: getEmptyPaginateObj(),
        // 上拉加载配置
        upOption: {
          // 首次自动执行
          auto: true,
          // 每页数据的数量; 默认10
          page: { size: pageSize },
          // 数量要大于3条才显示无更多数据
          noMoreSize: 3,
          // 返回顶部
          toTop: { right: 30, bottom: 48, zIndex: 9 }
        }
      }
    },
    watch: {
      // 监听query参数
      query: {
        handler({ categoryId1, categoryId2 }) {
          this.curIndex = this.findCateIndex(categoryId1)
          this.curIndex2 = this.findCateIndex(categoryId2, this.curIndex)
          this.showSubCate = false
          this.onRefreshList()
        },
        immediate: true
      }
    },
    computed: {
      // 二级分类列表
      subCateList() {
        if (this.list[this.curIndex] && this.list[this.curIndex].children) {
          return this.list[this.curIndex].children
        }
        return []
      }
    },
    methods: {

      // 根据分类ID查找指针
      findCateIndex(cateId, pIndex = -1) {
        if (!cateId) return -1
        const data = pIndex > -1 ? this.list[pIndex].children : this.list
        return data.findIndex(item => item.category_id == cateId)
      },

      /**
       * 上拉加载的回调 (页面初始化时也会执行一次)
       * 其中page.num:当前页 从1开始, page.size:每页数据条数,默认10
       * @param {Object} page
       */
      upCallback(page) {
        const app = this
        // 设置列表数据
        app.getGoodsList(page.num)
          .then(list => {
            const curPageLen = list.data.length
            const totalSize = list.total
            app.mescroll.endBySize(curPageLen, totalSize)
          })
          .catch(() => app.mescroll.endErr())
      },

      /**
       * 获取商品列表
       * @param {Number} pageNo 页码
       */
      getGoodsList(pageNo = 1) {
        const app = this
        const categoryId = app.getCategoryId()
        return new Promise((resolve, reject) => {
          GoodsApi.list({ categoryId, page: pageNo }, { load: false })
            .then(result => {
              const newList = result.data.list
              app.goodsList.data = getMoreListData(newList, app.goodsList, pageNo)
              app.goodsList.last_page = newList.last_page
              resolve(newList)
            })
            .catch(reject)
        })
      },

      // 获取当前选择的分类ID
      getCategoryId() {
        const app = this
        if (app.curIndex2 > -1) {
          return app.subCateList[app.curIndex2].category_id
        }
        return app.curIndex > -1 ? app.list[app.curIndex].category_id : 0
      },

      // 一级分类：选中分类
      handleSelectNav(index) {
        this.curIndex = index
        this.showSubCate = false
        this.onRefreshList()
        this.curIndex2 = -1
      },

      // 二级分类：选中分类
      handleSelectSubCate(index) {
        this.curIndex2 = index
        this.showSubCate = false
        this.onRefreshList()
      },

      // 刷新列表数据
      onRefreshList() {
        this.goodsList = getEmptyPaginateObj()
        setTimeout(() => this.mescroll.resetUpScroll(), 120)
      },

      // 跳转至商品列表页
      onTargetGoods(goodsId) {
        this.$navTo('pages/goods/detail', { goodsId })
      },

      // 点击加入购物车
      handleAddCart(item) {
        this.$refs.AddCartPopup.handle(item)
      },

      // 更新购物车角标
      onUpdateCartTabBadge() {
        console.log('onUpdateCartTabBadge')
        setCartTabBadge()
      },

      // 切换子分类显示状态
      handleShowSubCate() {
        this.showSubCate = !this.showSubCate
      }

    }
  }
</script>

<style lang="scss" scoped>
  .container {
    padding-left: 173rpx;
  }

  // 分类内容
  .cate-content {
    z-index: 1;
    background: #fff;
    padding-top: 90rpx;
    min-height: 300rpx;
  }

  // 一级分类+二级分类 20
  .cate-left {
    position: fixed;
    top: calc(88rpx + var(--window-top));
    left: var(--window-left);
    bottom: var(--window-bottom);
    width: 173rpx;
    height: calc(100% - var(--window-top) - var(--window-bottom) - 90rpx) !important;
    background: #f8f8f8;
    color: #444;
  }

  // 左侧一级分类
  .type-nav {
    position: relative;
    height: 90rpx;
    z-index: 10;
    display: block;
    font-size: 26rpx;
    display: flex;
    justify-content: center;
    align-items: center;

    &.selected {
      background: #fff;
      border-right: none;
      font-size: 28rpx;
      color: $main-bg
    }
  }

  // 商品列表
  .goods-list {
    background: #fff;
    position: relative;
  }

  .goods-item {
    padding: 28rpx 22rpx;
    display: flex;
  }

  .goods-item-left {
    position: relative;
    background: #fff;
    margin-right: 20rpx;

    .image {
      display: block;
      width: 180rpx;
      height: 180rpx;
    }
  }

  .goods-item-right {
    position: relative;
    flex: 1;

    .goods-name {
      display: block;
      width: 100%;
      font-size: 27rpx;
      // min-height: 68rpx;
      // line-height: 1.3;
      color: #333;
    }
  }

  .goods-item-desc {
    margin-top: 20rpx;

    .people {
      margin-right: 14rpx;
    }

    .desc-footer {
      width: 100%;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: absolute;
      right: 0rpx;
      bottom: 0rpx;
      min-height: 44rpx;

      .item-prices {
        padding-right: 6rpx;

        .price-x {
          margin-right: 14rpx;
          color: $main-bg;
          font-size: 28rpx;
        }

        .price-y {
          color: #999;
          text-decoration: line-through;
          font-size: 24rpx;
        }
      }

    }
  }


  // 子分类
  .sub-cate-list {
    background-color: #fff;
    width: 100%;
    z-index: 9;
    padding: 8rpx 40rpx 0 14rpx;
    overflow: hidden;
    position: sticky;
    top: calc(88rpx + var(--window-top));

    &.display-fold {
      height: 86rpx;
    }

    .nav-icon {
      position: absolute;
      right: 16rpx;
      top: 12rpx;
      font-size: 32rpx;
    }

    .sub-cate-item {
      float: left;
      background: #f8f8f8;
      padding: 10rpx 30rpx;
      margin-right: 22rpx;
      margin-bottom: 24rpx;
      font-size: 26rpx;
      border-radius: 14rpx;
      border: 1rpx solid #f8f8f8;

      &.selected {
        color: $main-bg;
        border: 1rpx solid $main-bg;
      }
    }
  }

  // 子分类遮罩层
  .mask {
    position: absolute;
    top: 0;
    bottom: 0;
    right: 0;
    left: 0;
    z-index: 8;
    background-color: rgba(0, 0, 0, 0.4);
    transition: all 0.3s ease-in-out 0s;
  }
</style>