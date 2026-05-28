import request from '@/utils/request'

// api地址
const api = {
  list: 'goods/list',
  detail: 'goods/detail',
  basic: 'goods/basic',
  specData: 'goods/specData',
  skuInfo: 'goods/skuInfo'
}

// 商品列表
export const list = (param, option) => {
  return request.get(api.list, param, option)
}

// 商品详情(详细数据)
export const detail = (goodsId, verifyStatus = true, param = {}) => {
  verifyStatus = Number(verifyStatus)
  return request.get(api.detail, { goodsId, verifyStatus, ...param })
}

// 商品详情(基本数据)
export const basic = (goodsId, verifyStatus = true, param = {}) => {
  verifyStatus = Number(verifyStatus)
  return request.get(api.basic, { goodsId, verifyStatus, ...param })
}

// 获取商品规格数据
export const specData = (goodsId) => {
  return request.get(api.specData, { goodsId })
}

// 获取商品的指定SKU信息
export const skuInfo = (goodsId, goodsSkuId, param) => {
  return request.get(api.skuInfo, { goodsId, goodsSkuId, ...param })
}
