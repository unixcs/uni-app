<template>
  <div class="components">
    <a-collapse :defaultActiveKey="componentKeys" :bordered="false" expandIconPosition="right">
      <a-collapse-panel v-for="group in data" :key="group.key" :header="group.name">
        <div class="module-list">
          <div
            v-for="(item, index) in group.data"
            :key="index"
            class="module-item"
            @click="handleClickItem(item.type)"
          >
            <a-icon class="module-icon" :component="item.icon" />
            <span class="module-title">{{ item.name }}</span>
          </div>
        </div>
      </a-collapse-panel>
    </a-collapse>
  </div>
</template>

<script>
import { filterModules } from '@/core/app'
import * as Icon from '@/assets/icons/page/components'

const data = [
  {
    name: '媒体组件',
    key: 'media',
    data: [
      {
        name: '轮播图',
        type: 'banner',
        icon: Icon.banner
      },
      {
        name: '图片',
        type: 'image',
        icon: Icon.image
      },
      {
        name: '图片橱窗',
        type: 'window',
        icon: Icon.window
      },
      {
        name: '热区',
        type: 'hotZone',
        icon: Icon.window
      },
      {
        name: '文章',
        type: 'article',
        icon: Icon.article
      },
      {
        name: '视频',
        type: 'video',
        icon: Icon.video
      },
      {
        name: '头条快报',
        type: 'special',
        icon: Icon.special
      },
      {
        name: '标题文本',
        type: 'title',
        icon: Icon.title
      }
    ]
  },
  {
    name: '商城组件',
    key: 'store',
    data: [
      {
        name: '搜索框',
        type: 'search',
        icon: Icon.search
      },
      {
        name: '导航组',
        type: 'navBar',
        icon: Icon.navBar
      },
      {
        name: '店铺公告',
        type: 'notice',
        icon: Icon.notice
      },
      {
        name: '商品组',
        type: 'goods',
        icon: Icon.goods
      },
      {
        name: '优惠券',
        type: 'coupon',
        icon: Icon.coupon,
        moduleKey: 'market-coupon'
      },
      {
        name: '在线客服',
        type: 'service',
        icon: Icon.service
      }
    ]
  },
  {
    name: '其他组件',
    key: 'other',
    data: [
      {
        name: '富文本',
        type: 'richText',
        icon: Icon.richText
      },
      {
        name: '辅助空白',
        type: 'blank',
        icon: Icon.blank
      },
      {
        name: '辅助线',
        type: 'guide',
        icon: Icon.guide
      },
      {
        name: '备案号',
        type: 'ICPLicense',
        icon: Icon.ICPLicense
      },
      {
        name: '关注公众号',
        type: 'officialAccount',
        icon: Icon.officialAccount
      },
    ]
  }
]

export default {
  data () {
    return { Icon, data }
  },
  computed: {
    componentKeys () {
      const { data } = this
      return data.map(item => item.key)
    }
  },
  methods: {
    handleClickItem (type) {
      this.$emit('handleClickItem', type)
    }
  }
}
</script>
<style lang="less" scoped>
::-webkit-scrollbar {
  width: 0;
  height: 0;
}

// 组件库
.components {
  width: 310px;
  background: #f8f9fa;
  height: 100%;
  overflow-y: auto;

  /deep/.ant-collapse-header {
    padding: 14px 16px;
    font-size: @font-size-base;
    font-weight: 700;
    color: #595961;
  }

  /deep/.ant-collapse-content-box {
    padding-top: 0 !important;
  }

  /deep/.ant-collapse > .ant-collapse-item {
    border-bottom: none;
  }

  .module-list {
    display: flex;
    flex-wrap: wrap;
  }

  .module-item {
    width: 90px;
    height: 90px;
    padding: 15px 0;
    text-align: center;
    font-size: 12px;
    color: #595961;
    line-height: 20px;
    position: relative;
    user-select: none;
    cursor: pointer;

    &:hover {
      background: #f7f7f7;
      border-radius: 4px;
    }
    .module-icon {
      display: block;
      width: 74px;
      height: 45px;
      line-height: 45px;
      margin: 0 auto;
      font-size: 26px;
      color: #9797a1;
    }
  }
}
</style>
