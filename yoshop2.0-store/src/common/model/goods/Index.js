import _ from 'lodash'
import CategoryModel from '../Category'
import * as GoodsApi from '@/api/goods'
import * as GradeApi from '@/api/user/grade'
import * as ServiceApi from '@/api/goods/service'

/**
 * 服务套餐 model类
 * GoodsModel
 */
export default {

  // 当前服务套餐ID
  goodsId: null,

  // 表单数据
  formData: {
    // 当前服务套餐记录
    goods: {},
    // 分类列表
    categoryList: [],
    // 服务与承诺
    serviceList: [],
    defaultServiceIds: [],
    // 会员等级列表
    userGradeList: [],
    defaultUserGradeValue: {}
  },

  // 获取form所需的数据
  getFromData (goodsId = null) {
    // 记录服务套餐ID (编辑时)
    this.goodsId = goodsId
    return new Promise((resolve, reject) => {
      Promise.all([
        // 获取服务套餐详情信息(编辑时)
        this.getGoodsDetail(goodsId),
        // 获取分类列表
        this.getCategoryList(),
        // 获取服务与承诺
        this.getServiceList(),
        // 获取会员等级列表
        this.getUserGradeList()
      ]).then(() => {
        // 设置默认数据
        this.setDefaultData()
        resolve({ formData: this.formData })
      })
    })
  },

  // 获取服务套餐详情
  getGoodsDetail (goodsId = null) {
    if (!goodsId) return false
    return new Promise((resolve, reject) => {
      GoodsApi.detail({ goodsId })
        .then(result => {
          this.formData.goods = result.data.goodsInfo
          resolve()
        })
    })
  },

  // 获取表单默认值(用于form.setFieldsValue的数据)
  getFieldsValue () {
    // 服务套餐详情信息
    const goodsInfo = this.formData.goods
    // 格式化categoryIds
    goodsInfo.categorys = this.formatCategoryIds(goodsInfo.categoryIds)
    // 服务套餐基本数据
    const goodsFormData = _.pick(goodsInfo, [
      'goods_type', 'goods_name', 'categorys', 'goods_no', 'sort',
      'status', 'spec_type', 'deduct_stock_type', 'is_restrict', 'content',
      'selling_point', 'serviceIds', 'sales_initial', 'is_points_gift',
      'is_points_discount', 'is_enable_grade', 'is_alone_grade'
    ])
    return {
      ...goodsFormData,
    }
  },

  // 第二次赋值 (适用于动态渲染的form-item)
  getFieldsValue2 () {
    const goodsInfo = this.formData.goods
    let goodsFormData = {}
    // 单规格数据
    if (goodsInfo.spec_type == 10) {
      const skuOne = _.pick(goodsInfo.skuList[0], ['goods_price', 'line_price', 'stock_num'])
      goodsFormData = { ...goodsFormData, ...skuOne }
    }
    // 服务套餐限购数据
    if (goodsInfo.is_restrict == 1) {
      goodsFormData['restrict_total'] = goodsInfo['restrict_total']
      goodsFormData['restrict_single'] = goodsInfo['restrict_single']
    }
    return goodsFormData
  },

  /**
   * 格式化categoryIds (用于表单元素选中)
   * @param {*} categoryIds
   */
  formatCategoryIds (categoryIds) {
    return categoryIds.map(id => { return { value: id } })
  },

  // 获取分类列表
  getCategoryList () {
    return new Promise((resolve, reject) => {
      CategoryModel.getCategoryTreeSelect()
        .then(list => {
          this.formData.categoryList = list
          resolve()
        })
    })
  },

  // 获取服务与承诺
  getServiceList () {
    return new Promise((resolve, reject) => {
      ServiceApi.all()
        .then(result => {
          this.formData.serviceList = result.data.list
          resolve()
        })
    })
  },

  // 获取会员等级列表
  getUserGradeList () {
    return new Promise((resolve, reject) => {
      GradeApi.all({ status: 1 })
        .then(result => {
          this.formData.userGradeList = result.data.list
          resolve()
        })
    })
  },

  // 设置默认的数据(无法用于form.setFieldsValue的数据)
  setDefaultData () {
    // 默认的服务与承诺
    this.setDefaultServiceIds()
    // 默认的等级折扣
    this.setDefaultUserGradeValue()
  },

  // 默认的服务与承诺
  setDefaultServiceIds () {
    // 服务与承诺列表
    const serviceList = this.formData.serviceList
    if (!this.goodsId) {
      // 默认选中的id集
      const defaultServiceItems = serviceList.filter(item => item.is_default)
      this.formData.defaultServiceIds = defaultServiceItems.map(item => item.service_id)
    }
  },

  // 默认的等级折扣
  setDefaultUserGradeValue () {
    // 会员等级列表
    const userGradeList = this.formData.userGradeList
    // 单独设置折扣的配置 (已保存的)
    const aloneGradeEquity = (this.goodsId && this.formData.goods.alone_grade_equity)
      ? this.formData.goods.alone_grade_equity : {}
    // 生成的默认数据
    const defaultData = {}
    userGradeList.forEach(item => {
      defaultData[item.grade_id] = aloneGradeEquity[item.grade_id] || item.equity.discount
    })
    this.formData.defaultUserGradeValue = { ...defaultData }
  }

}
