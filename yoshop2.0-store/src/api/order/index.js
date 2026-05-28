import { axios } from '@/utils/request'

// api接口列表
const api = {
  list: '/order/list',
  detail: '/order/detail'
}

// 列表记录
export function list (params) {
  return axios({
    url: api.list,
    method: 'get',
    params
  })
}

// 订单详情
export function detail (params) {
  return axios({
    url: api.detail,
    method: 'get',
    params
  })
}

// 订单导出
export function exports (params) {
  return axios({
    url: api.detail,
    method: 'get',
    params
  })
}
