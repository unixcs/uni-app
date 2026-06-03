import { filterModules } from '@/core/app'

// 链接类型 - 指定的页面
export const LINK_TYPE_PAGE = 'PAGE'

// 链接类型 - 自定义路径
export const LINK_TYPE_CUSTOM = 'CUSTOM'

// 链接类型 - 跳转微信小程序
export const LINK_TYPE_MP_WEIXIN = 'MP-WEIXIN'

// 链接类型 - H5外链
export const LINK_TYPE_URL = 'URL'

// 基础页面
const basics = {
  title: '基础页面',
  key: 'basics',
  data: [
    {
      id: 'cb344ba',
      title: '商城首页',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/index/index'
      }
    },
    {
      id: '296fe6f',
      title: '分类页',
      type: LINK_TYPE_PAGE,

      alert: '分类ID参数仅支持分类页 [分类+商品] 模版，用于选中指定的分类项',
      param: {
        path: 'pages/category/index',
        query: {}
      },
      form: [
        {
          key: 'query.categoryId1',
          lable: '分类ID (一级)',
          // required: true,
          tips: '商品管理 -> 商品分类'
        },
        {
          key: 'query.categoryId2',
          lable: '分类ID (二级)',
          tips: '商品管理 -> 商品分类'
        }
      ]
    },
    {
      id: 'bb2f7f1',
      title: '购物车',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/cart/index'
      }
    },
    {
      id: 'a013c9e',
      title: '个人中心',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/user/index'
      }
    },
    {
      id: '593fe1f',
      title: '会员登录页',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/login/index'
      }
    }
  ]
}

// 商城页面
const store = {
  title: '商城页面',
  key: 'store',
  data: [
    {
      id: '995bf1c',
      title: '商品列表页',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/goods/list',
        query: {}
      },
      form: [
        {
          key: 'query.categoryId',
          lable: '分类ID',
          tips: '商品管理 -> 商品分类'
        },
        {
          key: 'query.search',
          lable: '关键词',
          tips: '搜索的关键词，用于匹配商品名称'
        }
      ]
    },
    {
      id: '6wawb10',
      title: '商品详情页',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/goods/detail',
        query: {}
      },
      form: [
        {
          key: 'query.goodsId',
          lable: '商品ID',
          required: true,
          tips: '商品管理 -> 商品列表' // 字段提示
        }
      ]
    },
    {
      id: '88lxeey',
      title: '商品搜索页',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/search/index'
      }
    },
    {
      id: '56sswhq',
      title: '领券中心',
      type: LINK_TYPE_PAGE,
      moduleKey: 'market-coupon',
      param: {
        path: 'pages/coupon/index'
      }
    }
  ]
}

// 个人中心
const personal = {
  title: '个人中心',
  key: 'personal',
  data: [
    {
      id: '7b345f6',
      title: '我的订单',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/order/index',
        query: {}
      },
      form: [
        {
          key: 'query.dataType',
          lable: '订单类型',
          required: true,
          value: 'all', // 默认值
          tips: 'all 全部<br>payment 待支付<br>contact 待联系<br>in_service 服务中<br>complete 已完成<br>cancel 已关闭<br>refund 已退款' // 字段提示
        }
      ]
    },
    {
      id: 'c4f630d',
      title: '我的钱包页',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/wallet/index'
      }
    },
    {
      id: 'aaac87',
      title: '个人信息',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/user/personal/index'
      }
    },
    {
      id: '792db19',
      title: '充值中心页',
      type: LINK_TYPE_PAGE,
      moduleKey: 'market-recharge',
      param: {
        path: 'pages/wallet/recharge/index'
      }
    },
    {
      id: '03b9290',
      title: '我的优惠券',
      type: LINK_TYPE_PAGE,
      moduleKey: 'market-coupon',
      param: {
        path: 'pages/my-coupon/index'
      }
    },
    {
      id: '569b0b0',
      title: '积分明细',
      type: LINK_TYPE_PAGE,
      moduleKey: 'market-points',
      param: {
        path: 'pages/points/log'
      }
    },
    {
      id: '0c25051',
      title: '收货地址',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/address/index'
      }
    },
    {
      id: '715d4ed',
      title: '订单中心',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/order/center'
      }
    },
    {
      id: '3558c27',
      title: '帮助中心',
      type: LINK_TYPE_PAGE,
      moduleKey: 'content-help',
      param: {
        path: 'pages/help/index'
      }
    }
  ]
}

// 其他页面
const other = {
  title: '其他页面',
  key: 'other',
  data: [
    {
      id: '91th4ss',
      title: '店铺页面',
      type: LINK_TYPE_PAGE,
      param: {
        path: 'pages/custom/index',
        query: {}
      },
      form: [
        {
          key: 'query.pageId',
          lable: '页面ID',
          required: true,
          tips: '店铺管理 -> 店铺页面'
        }
      ]
    },
    {
      id: 'ugrauzv',
      title: '资讯列表页',
      type: LINK_TYPE_PAGE,
      moduleKey: 'content-article',
      param: {
        path: 'pages/article/index',
        query: {}
      },
      form: [
        {
          key: 'query.categoryId',
          lable: '分类ID',
          tips: '内容管理 -> 文章分类',
          value: ''
        }
      ]
    },
    {
      id: 'u1v6aux',
      title: '资讯详情页',
      type: LINK_TYPE_PAGE,
      moduleKey: 'content-article',
      param: {
        path: 'pages/article/detail',
        query: {}
      },
      form: [
        {
          key: 'query.articleId',
          lable: '文章ID',
          required: true,
          tips: '内容管理 -> 文章列表'
        }
      ]
    },
    {
      id: '0b0147a',
      title: '自定义路径',
      type: LINK_TYPE_CUSTOM,
      alert: '仅支持跳转内部页面路径，例如：pages/index/index',
      param: {
        path: '',
        queryStr: {}
      },
      form: [
        {
          key: 'path',
          lable: '页面路径',
          required: true,
          tips: '请输入以pages开头的路径'
        },
        {
          key: 'queryStr',
          lable: 'query参数',
          required: false
        }
      ]
    },
    {
      id: 'e234986',
      title: '跳转微信小程序',
      type: LINK_TYPE_MP_WEIXIN,
      alert: '仅支持在微信小程序之间跳转，不支持从其他客户端跳转小程序',
      param: {
        appId: '',
        path: ''
      },
      form: [
        {
          key: 'appId',
          lable: '小程序AppID',
          required: true,
          tips: '填写要跳转的微信小程序AppID'
        },
        {
          key: 'path',
          lable: '小程序路径',
          // required: true,
          tips: '填写要跳转的微信小程序路径'
        }
      ]
    },
    {
      id: '4e99fde',
      title: 'H5外部链接',
      type: LINK_TYPE_URL,
      alert: '微信小程序端需配置业务域名才可以访问 <a href="https://developers.weixin.qq.com/miniprogram/dev/framework/ability/domain.html" target="_blank">查看文档</a>',
      param: { url: '' },
      form: [
        {
          key: 'url',
          lable: '链接地址',
          required: true,
          tips: '请输入以 https:// 或 http:// 开头的链接'
        }
      ]
    },
  ]
}

export const linkList = [basics, store, personal, other]
