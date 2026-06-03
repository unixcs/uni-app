<template>
  <view class="page-items">
    <block v-for="(item, index) in items" :key="index">
      <!-- 搜索框 -->
      <block v-if="item.type === 'search'">
        <Search :itemStyle="item.style" :params="item.params" />
      </block>
      <!-- 图片组 -->
      <block v-if="item.type === 'image'">
        <Images :itemStyle="item.style" :params="item.params" :dataList="item.data" />
      </block>
      <!-- 轮播图 -->
      <block v-if="item.type === 'banner'">
        <Banner :itemStyle="item.style" :params="item.params" :dataList="item.data" />
      </block>
      <!-- 图片橱窗 -->
      <block v-if="item.type === 'window'">
        <Window :itemStyle="item.style" :params="item.params" :dataList="item.data" />
      </block>
      <!-- 视频组 -->
      <block v-if="item.type === 'video'">
        <Videos :itemStyle="item.style" :params="item.params" />
      </block>
      <!-- 文章组 -->
      <block v-if="item.type === 'article'">
        <Article :params="item.params" :dataList="item.data" />
      </block>
      <!-- 店铺公告 -->
      <block v-if="item.type === 'notice'">
        <Notice :itemStyle="item.style" :params="item.params" />
      </block>
      <!-- 导航组 -->
      <block v-if="item.type === 'navBar'">
        <NavBar :itemStyle="item.style" :params="item.params" :dataList="item.data" />
      </block>
      <!-- 商品组 -->
      <block v-if="item.type === 'goods'">
        <Goods :itemStyle="item.style" :params="item.params" :dataList="item.data" />
      </block>
      <!-- 在线客服 -->
      <block v-if="item.type === 'service'">
        <Service :itemStyle="item.style" :params="item.params" />
      </block>
      <!-- 辅助空白 -->
      <block v-if="item.type === 'blank'">
        <Blank :itemStyle="item.style" />
      </block>
      <!-- 辅助线 -->
      <block v-if="item.type === 'guide'">
        <Guide :itemStyle="item.style" />
      </block>
      <!-- 富文本 -->
      <block v-if="item.type === 'richText'">
        <RichText :itemStyle="item.style" :params="item.params" />
      </block>
      <!-- 头条快报 -->
      <block v-if="item.type === 'special'">
        <Special :itemStyle="item.style" :params="item.params" :dataList="item.data" />
      </block>
      <!-- 关注公众号 -->
      <block v-if="item.type === 'officialAccount'">
        <DiyOfficialAccount />
      </block>
      <!-- 优惠券 -->
      <block v-if="item.type === 'coupon'">
        <Coupon :itemStyle="item.style" :params="item.params" :dataList="item.data" />
      </block>
      <!-- 图片组 -->
      <block v-if="item.type === 'hotZone'">
        <HotZone :itemStyle="item.style" :params="item.params" :data="item.data" />
      </block>
      <!-- 备案号 -->
      <block v-if="item.type === 'ICPLicense'">
        <ICPLicense :itemStyle="item.style" :params="item.params" />
      </block>
      <!-- 文字标题 -->
      <block v-if="item.type === 'title'">
        <Title :itemStyle="item.style" :params="item.params" />
      </block>
    </block>
  </view>
</template>

<script>
  import Search from './diyComponents/search'
  import Images from './diyComponents/image'
  import Banner from './diyComponents/banner'
  import Window from './diyComponents/window'
  import HotZone from './diyComponents/hotZone'
  import Videos from './diyComponents/video'
  import Article from './diyComponents/article'
  import Notice from './diyComponents/notice'
  import NavBar from './diyComponents/navBar'
  import Goods from './diyComponents/goods'
  import Service from './diyComponents/service'
  import Blank from './diyComponents/blank'
  import Guide from './diyComponents/guide'
  import RichText from './diyComponents/richText'
  import Special from './diyComponents/special'
  import DiyOfficialAccount from './diyComponents/officialAccount'
  import Coupon from './diyComponents/coupon'
  import ICPLicense from './diyComponents/ICPLicense'
  import Title from './diyComponents/title'

  export default {
    name: "Page",
    components: {
      Search,
      Images,
      Banner,
      Window,
      HotZone,
      Videos,
      Article,
      Notice,
      NavBar,
      Goods,
      Service,
      Blank,
      Guide,
      RichText,
      Special,
      DiyOfficialAccount,
      Coupon,
      ICPLicense,
      Title
    },
    /**
     * 组件的属性列表
     * 用于组件自定义设置
     */
    props: {
      items: {
        type: Array,
        default () {
          return []
        }
      }
    },
  }
</script>
<style lang="scss">
  // 组件样式
</style>