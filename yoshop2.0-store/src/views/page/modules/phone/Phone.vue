<template>
  <div ref="phone-content" class="phone-content">
    <!-- 顶部导航栏 -->
    <div
      class="phone-top optional"
      :class="{ selected: 'page' === selectedIndex, [data.page.style.titleTextColor]: true }"
      :style="{ backgroundColor: data.page.style.titleBackgroundColor }"
      @click="handelClickItem('page')"
    >
      <p
        class="title"
        :style="{ color: data.page.style.titleTextColor }"
      >{{ data.page.params.title }}</p>
    </div>
    <!-- 内容区域 -->
    <div class="phone-main">
      <draggable
        :list="data.items"
        class="content"
        @update="handelDragItem"
        v-bind="{ animation: 120, filter: '.undrag' }"
      >
        <!-- 内容元素 -->
        <div
          class="devise-item optional"
          v-for="(item, index) in data.items"
          :key="index"
          @click="handelClickItem(index)"
          :class="{ selected: index === selectedIndex, undrag: inArray(item.type, undragList) }"
          :style="renderItemStyle(item)"
        >
          <!-- 轮播图 -->
          <div
            v-if="item.type == 'banner'"
            class="diy-banner"
            :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px`, background: item.style.background }"
          >
            <div
              class="swiper-item"
              v-show="dataIdx <= 1"
              v-for="(dataItem, dataIdx) in item.data"
              :key="`${index}_${dataIdx}_img`"
              :style="{ borderRadius: `${item.style.borderRadius}px` }"
            >
              <img class="image" :src="dataItem.imgUrl" />
            </div>
            <div
              class="indicator-dots"
              :class="item.style.btnShape"
              :style="{ '--padding-top': `${item.style.paddingTop}px` }"
            >
              <div
                v-for="(dataItem, dataIdx) in item.data"
                :key="`${index}_${dataIdx}_dots`"
                :style="{ background: item.style.btnColor }"
                class="dots-item"
              ></div>
            </div>
          </div>

          <!-- 图片组 -->
          <div
            v-else-if="item.type == 'image'"
            class="diy-image"
            :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px`, background: item.style.background }"
          >
            <div
              class="item-image"
              v-for="(dataItm, dataIdx) in item.data"
              :key="`${index}_${dataIdx}`"
              :style="{ marginBottom: `${item.style.itemMargin}px`, borderRadius: `${item.style.borderRadius}px` }"
            >
              <img class="image" :src="dataItm.imgUrl" />
            </div>
          </div>

          <!-- 图片橱窗 -->
          <div
            v-else-if="item.type == 'window'"
            class="diy-window"
            :style="{ background: item.style.background, padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px` }"
          >
            <ul
              v-if="item.style.layout > -1"
              class="data-list clearfix"
              :class="`avg-sm-${item.style.layout}`"
            >
              <li
                v-for="(window, dataIdx) in item.data"
                :key="`${index}_${dataIdx}`"
                class="data-item"
                :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px` }"
              >
                <div class="item-image">
                  <img class="image" :src="window.imgUrl" />
                </div>
              </li>
            </ul>
            <div class="display" v-else>
              <div
                class="display-left"
                :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px` }"
              >
                <img class="image" :src="item.data[0].imgUrl" />
              </div>
              <div class="display-right">
                <div
                  v-if="item.data.length >= 2"
                  class="display-right1"
                  :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px` }"
                >
                  <img class="image" :src="item.data[1].imgUrl" />
                </div>
                <div class="display-right2">
                  <div
                    v-if="item.data.length >= 3"
                    class="left"
                    :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px` }"
                  >
                    <img class="image" :src="item.data[2].imgUrl" />
                  </div>
                  <div
                    v-if="item.data.length >= 4"
                    class="right"
                    :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px` }"
                  >
                    <img class="image" :src="item.data[3].imgUrl" />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 热区组 -->
          <div
            v-else-if="item.type == 'hotZone'"
            class="diy-hotZone"
            :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px`, background: item.style.background }"
          >
            <div class="bg-image" :style="{ borderRadius: `${item.style.borderRadius}px` }">
              <img class="image" :src="item.data.imgUrl" />
            </div>
          </div>

          <!-- 视频组 -->
          <div
            v-else-if="item.type == 'video'"
            class="diy-video"
            :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px`, background: item.style.background }"
          >
            <video
              :style="{ height: `${item.style.height}px` }"
              :src="item.params.videoUrl"
              :poster="item.params.poster"
              controls
            >您的浏览器不支持 video 标签</video>
          </div>

          <!-- 文章组 -->
          <div v-else-if="item.type == 'article'" class="diy-article">
            <div
              class="article-item"
              v-for="(dataItm, dataIdx) in (item.params.source == 'choice' && item.data.length ? item.data : item.defaultData)"
              :key="`${index}_${dataIdx}`"
              :class="`show-type__${dataItm.show_type}`"
            >
              <!-- 小图模式 -->
              <template v-if="dataItm.show_type == 10">
                <div class="article-item__left flex-box">
                  <div class="article-item__title twoline-hide">
                    <span class="article-title">{{ dataItm.title }}</span>
                  </div>
                  <div class="article-item__footer">
                    <span class="article-views">{{ dataItm.views_num }}次浏览</span>
                  </div>
                </div>
                <div class="article-item__image">
                  <img class="image" :src="dataItm.image" alt />
                </div>
              </template>
              <!-- 大图模式 -->
              <template v-if="dataItm.show_type == 20">
                <div class="article-item__title">
                  <span class="article-title">{{ dataItm.title }}</span>
                </div>
                <div class="article-item__image">
                  <img class="image" :src="dataItm.image" />
                </div>
                <div class="article-item__footer">
                  <span class="article-views">{{ dataItm.views_num }}次浏览</span>
                </div>
              </template>
            </div>
          </div>

          <!-- 搜索栏 -->
          <div
            v-else-if="item.type == 'search'"
            class="diy-search"
            :class="{ sticky: item.params.sticky }"
            :style="{ background: item.style.background, padding: `${item.style.paddingY}px ${item.style.paddingX}px` }"
          >
            <div class="inner" :class="item.style.searchStyle">
              <div
                class="search-input"
                :style="{ textAlign: item.style.textAlign, background: item.style.searchBg, color: item.style.searchFontColor }"
              >
                <a-icon class="search-icon" :component="PageIcon.search" />
                <span>{{ item.params.placeholder }}</span>
              </div>
            </div>
          </div>

          <!-- 店铺公告 -->
          <div
            v-else-if="item.type == 'notice'"
            class="diy-notice"
            :style="{ padding: `${item.style.paddingTop}px 0` }"
          >
            <div
              class="notice-body"
              :style="{ background: item.style.background, color: item.style.textColor }"
            >
              <div class="notice__icon">
                <a-icon class="notice-icon" :component="PageIcon.volumeFill" />
              </div>
              <div
                class="notice__text flex-box oneline-hide"
                :style="{ fontSize: `${item.style.fontSize}px` }"
              >
                <span>{{ item.params.text }}</span>
              </div>
            </div>
          </div>

          <!-- 导航组 -->
          <div
            v-else-if="item.type == 'navBar'"
            class="diy-navBar"
            :style="{ padding: `${item.style.paddingTop}px 0`, background: item.style.background, color: item.style.textColor }"
          >
            <div class="data-list clearfix" :class="`avg-sm-${item.style.rowsNum}`">
              <div
                class="item-nav"
                v-for="(dataItm, dataIdx) in item.data"
                :key="`${index}_${dataIdx}`"
              >
                <div class="item-image">
                  <img
                    class="image"
                    :style="{ width: `${item.style.imageSize}px`, height: `${item.style.imageSize}px` }"
                    :src="dataItm.imgUrl"
                  />
                </div>
                <p class="item-text oneline-hide">{{ dataItm.text }}</p>
              </div>
            </div>
          </div>

          <!-- 商品组 -->
          <div
            v-else-if="item.type == 'goods'"
            class="diy-goods"
            :style="{ background: item.style.background, padding: `${item.style.paddingY}px ${item.style.paddingX}px` }"
          >
            <div
              class="goods-list"
              :class="[`display-${item.style.display}`, `column-${item.style.column}` ]"
              :style="{ marginBottom: `-${item.style.itemMargin}px` }"
            >
              <div
                class="goods-item"
                v-for="(dataItm, dataIdx) in (item.params.source == 'choice' && item.data.length ? item.data : item.defaultData)"
                :key="`${index}_${dataIdx}`"
                :class="[`display-${item.style.cardType}`]"
                :style="{ marginBottom: `${item.style.itemMargin}px`, borderRadius: `${item.style.borderRadius}px` }"
              >
                <!-- 单列商品 -->
                <template v-if="item.style.column == 1">
                  <div class="flex">
                    <!-- 商品图片 -->
                    <div class="goods-item-left">
                      <img class="image" :src="dataItm.goods_image" />
                    </div>
                    <div class="goods-item-right">
                      <div class="goods-info">
                        <div
                          v-if="inArray('goodsName', item.style.show)"
                          class="goods-name"
                          :class="[ item.style.goodsNameRows == 'two' ? 'twoline-hide' : 'oneline-hide', `row-${item.style.goodsNameRows}` ]"
                        >{{ dataItm.goods_name }}</div>
                        <div v-if="inArray('sellingPoint', item.style.show)" class="goods-selling">
                          <span
                            class="selling oneline-hide"
                            :style="{ color: item.style.sellingColor }"
                          >{{ dataItm.selling_point }}</span>
                        </div>
                        <div
                          v-if="inArray('goodsSales', item.style.show)"
                          class="goods-sales oneline-hide"
                        >
                          <span class="sales">已售{{ dataItm.goods_sales }}</span>
                          <!-- <span class="line">|</span>
                          <span>惊艳度100%</span>-->
                        </div>
                        <div class="footer">
                          <div
                            v-if="inArray('goodsPrice', item.style.show)"
                            class="goods-price oneline-hide"
                            :style="{ color: item.style.priceColor }"
                          >
                            <span class="unit">￥</span>
                            <span class="value">{{ dataItm.goods_price_min }}</span>
                            <!-- <span class="unit2">到手价</span> -->
                            <span v-if="inArray('linePrice', item.style.show)" class="line-price">
                              <span class="unit">￥</span>
                              <span class="value">{{ dataItm.line_price_min }}</span>
                            </span>
                          </div>
                          <div
                            v-show="inArray('cartBtn', item.style.show) && item.style.column < 3"
                            class="action"
                          >
                            <div class="btn-cart" :style="{ color: item.style.btnCartColor }">
                              <a-icon
                                class="cart-icon"
                                :component="PageIcon[`jiagou${item.style.btnCartStyle}`]"
                              />
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>
                <!-- 两列/三列 -->
                <template v-else>
                  <div class="goods-image">
                    <img class="image" :src="dataItm.goods_image" />
                  </div>
                  <div class="goods-info">
                    <div
                      v-if="inArray('goodsName', item.style.show)"
                      class="goods-name"
                      :class="[ item.style.goodsNameRows == 'two' ? 'twoline-hide' : 'oneline-hide', `row-${item.style.goodsNameRows}` ]"
                    >{{ dataItm.goods_name }}</div>
                    <div v-if="inArray('sellingPoint', item.style.show)" class="goods-selling">
                      <span
                        class="selling oneline-hide"
                        :style="{ color: item.style.sellingColor }"
                      >{{ dataItm.selling_point }}</span>
                    </div>
                    <div
                      v-if="inArray('goodsSales', item.style.show)"
                      class="goods-sales oneline-hide"
                    >
                      <span class="sales">已售{{ dataItm.goods_sales }}</span>
                      <!-- <span class="line">|</span>
                      <span>惊艳度100%</span>-->
                    </div>
                    <div class="footer">
                      <div
                        v-if="inArray('goodsPrice', item.style.show)"
                        class="goods-price oneline-hide"
                        :style="{ color: item.style.priceColor }"
                      >
                        <span class="unit">￥</span>
                        <span class="value">{{ dataItm.goods_price_min }}</span>
                        <!-- <span class="unit2">到手价</span> -->
                        <span v-if="inArray('linePrice', item.style.show)" class="line-price">
                          <span class="unit">￥</span>
                          <span class="value">{{ dataItm.line_price_min }}</span>
                        </span>
                      </div>
                      <div
                        v-show="inArray('cartBtn', item.style.show) && item.style.column < 3"
                        class="action"
                      >
                        <div class="btn-cart" :style="{ color: item.style.btnCartColor }">
                          <a-icon
                            class="cart-icon"
                            :component="PageIcon[`jiagou${item.style.btnCartStyle}`]"
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </div>

          <!-- 辅助空白 -->
          <div
            v-else-if="item.type == 'blank'"
            class="diy-blank"
            :style="{ height: `${item.style.height}px` , background: item.style.background }"
          ></div>

          <!-- 辅助线 -->
          <div
            v-else-if="item.type == 'guide'"
            class="diy-guide"
            :style="{ padding: `${item.style.paddingTop}px 0`, background: item.style.background }"
          >
            <p
              class="line"
              :style="{
                borderTopWidth: item.style.lineHeight + 'px',
                borderTopColor: item.style.lineColor,
                borderTopStyle: item.style.lineStyle
              }"
            ></p>
          </div>

          <!-- 在线客服 -->
          <div
            v-else-if="item.type == 'service'"
            class="diy-service"
            :style="{ opacity: item.style.opacity / 100 }"
          >
            <div class="service-icon">
              <img class="image" :src="item.params.image" alt />
            </div>
          </div>

          <!-- 富文本 -->
          <div
            v-else-if="item.type == 'richText'"
            class="diy-richText"
            :style="{ background: item.style.background, padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px` }"
            v-html="item.params.content"
          ></div>

          <!-- 关注公众号 -->
          <div v-else-if="item.type == 'officialAccount'" class="diy-officialAccount">
            <div class="item-top">
              <span>关联的公众号</span>
            </div>
            <div class="item-content">
              <div class="item-cont-avatar">
                <img class="image" src="~@/assets/img/circular.png" alt />
              </div>
              <div class="item-cont-public">
                <div class="public-name">
                  <span>公众号名称</span>
                </div>
                <div class="public-describe">
                  <span>公众号简介公众号简介公众号简介</span>
                </div>
              </div>
              <div class="item-cont-active">
                <div class="active-btn">
                  <span>关注</span>
                </div>
              </div>
            </div>
          </div>

          <!-- 优惠券 -->
          <div
            v-else-if="item.type == 'coupon'"
            class="diy-coupon"
            :style="{ padding: `${item.style.paddingTop}px 0`, background: item.style.background }"
          >
            <div class="coupon-wrapper">
              <div
                class="coupon-item"
                v-for="(coupon, idx) in (item.params.source == 'choice' && item.data.length ? item.data : item.defaultData)"
                :key="idx"
                :style="{ marginRight: `${item.style.marginRight}px` }"
              >
                <i class="before" :style="{ background: item.style.background }"></i>
                <div
                  class="left-content"
                  :style="{ background: item.style.couponBgColor, color: item.style.couponTextColor }"
                >
                  <div class="content-top">
                    <template v-if="coupon.coupon_type == 10">
                      <span class="unit">￥</span>
                      <span class="price">{{ coupon.reduce_price }}</span>
                    </template>
                    <template v-if="coupon.coupon_type == 20">
                      <span class="price">{{ coupon.discount }}折</span>
                    </template>
                  </div>
                  <div class="content-bottom">
                    <span>满{{ coupon.min_price }}元可用</span>
                  </div>
                </div>
                <div
                  class="right-receive"
                  :style="{ background: item.style.receiveBgColor, color: item.style.receiveTextColor }"
                >
                  <span>立即</span>
                  <span>领取</span>
                </div>
              </div>
            </div>
          </div>

          <!-- 头条快报 -->
          <div
            v-else-if="item.type == 'special'"
            class="diy-special"
            :style="{ padding: `${item.style.paddingTop}px 0`, background: item.style.background }"
          >
            <div class="special-left">
              <img class="image" :src="item.params.image" alt />
            </div>
            <div class="special-content" :class="[`display_${item.params.display}`]">
              <ul class="special-content-list">
                <li
                  class="content-item oneline-hide"
                  v-for="(dataItm, idx) in (item.params.source == 'choice' && item.data.length ? item.data : item.defaultData)"
                  :key="idx"
                >
                  <span :style="{ color: item.style.textColor }">{{ dataItm.title }}</span>
                </li>
              </ul>
            </div>
            <div class="special-more">
              <a-icon :component="Icon.arrowRight" />
            </div>
          </div>

          <!-- 网站备案号 -->
          <div
            v-else-if="item.type == 'ICPLicense'"
            class="diy-ICPLicense"
            :style="{ padding: `${item.style.paddingTop}px ${item.style.paddingLeft}px`, background: item.style.background }"
          >
            <p class="line" :style="{ textAlign: item.style.textAlign }">
              <a
                :style="{ fontSize: item.style.fontSize + 'px', color: item.style.textColor }"
                :href="item.params.link"
                target="_blank"
              >{{ item.params.text }}</a>
            </p>
          </div>

          <!-- 标题文本 -->
          <div
            v-else-if="item.type == 'title'"
            class="diy-title"
            :style="{ padding: `${item.style.paddingY}px 15px`, background: item.style.background }"
          >
            <div class="title-content">
              <!-- 标题文字 -->
              <div class="title">
                <span
                  :style="{ color: item.style.titleTextColor, fontSize: `${item.params.titleFontSize}px`, fontWeight: item.params.titleFontWeight }"
                >{{ item.params.title }}</span>
              </div>
              <!-- 查看更多 -->
              <div
                v-if="item.params.more.enable"
                class="more-content"
                :style="{ color: item.style.moreTextColor }"
              >
                <span class="more-text">{{ item.params.more.text }}</span>
                <span v-if="item.params.more.enableIcon" class="more-icon">
                  <a-icon :component="Icon.arrowRight" />
                </span>
              </div>
            </div>
            <!-- 描述文字 -->
            <div class="desc-content">
              <span
                :style="{ color: item.style.descTextColor, fontSize: `${item.params.descFontSize}px`, fontWeight: item.params.descFontWeight }"
              >{{ item.params.desc }}</span>
            </div>
          </div>

          <!-- 操作工具栏 -->
          <div class="action-tools">
            <div class="tools-content">
              <div class="tools-item" v-for="(itm, idx) in actionTools(item, index)" :key="idx">
                <a-tooltip placement="right" :getPopupContainer="() => $refs['phone-content']">
                  <span class="tooltip-text" slot="title">{{ itm.title }}</span>
                  <div
                    class="item-btn"
                    :class="{ disabled: itm.disabled }"
                    @click.stop="handleActionToolItem(itm, index)"
                  >
                    <a-icon class="tools-icon" :type="itm.type" />
                  </div>
                </a-tooltip>
              </div>
            </div>
          </div>
        </div>
      </draggable>
    </div>
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import draggable from 'vuedraggable'
import { inArray } from '@/utils/util'
import * as PageIcon from '@/assets/icons/page'
import * as Icon from '@/core/icons'
import { UTag } from './modules'

// 禁止拖拽的组件
const undragList = ['service']

export default {
  props: {
    // 页面数据
    data: PropTypes.object.def({}),
    // 当前选中的元素索引
    selectedIndex: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).def(0)
  },
  components: {
    draggable,
    UTag
  },
  data () {
    return {
      // 禁止拖拽的组件
      undragList
    }
  },
  computed: {

  },
  beforeCreate () {
    this.Icon = Icon
    this.PageIcon = PageIcon
    this.inArray = inArray
  },
  methods: {

    /**
     * 操作工具栏
     * @param item
     * @param index
     */
    actionTools (item, index) {
      const { data: { items } } = this
      const isUndrag = inArray(item.type, undragList)
      return [
        {
          title: '上移',
          type: 'up',
          disabled: isUndrag || index == 0 || inArray(items[index - 1].type, undragList)
        },
        {
          title: '下移',
          type: 'down',
          disabled: isUndrag || items.length <= index + 1 || inArray(items[index + 1].type, undragList)
        },
        {
          title: '复制',
          type: 'copy',
          disabled: false
        },
        {
          title: '删除',
          type: 'delete',
          disabled: false
        }
      ]
    },

    /**
     * 拖动diy元素更新当前索引
     * @param e
     */
    handelDragItem (e) {
      this.$emit('onEditer', e.newIndex)
    },

    /**
     * 点击当前选中的Diy元素
     * @param index
     */
    handelClickItem (index) {
      this.$emit('onEditer', index)
    },

    // 操作工具栏点击事件
    handleActionToolItem (acItem, index) {
      !acItem.disabled && this.$emit('onActionItem', acItem.type, index)
    },

    // 渲染组件外层容器的样式
    renderItemStyle (item) {
      if (item.type === 'service') {
        return {
          position: 'absolute',
          right: item.style.right + 'px',
          bottom: item.style.bottom + 'px',
          zIndex: 999
        }
      }
      return {}
    }

  }
}
</script>
<style lang="less" scoped>
@import './style.less';
</style>
