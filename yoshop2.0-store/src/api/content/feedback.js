import { axios } from '@/utils/request'

// api接口列表
const api = {
  list: '/content.feedback/list',
  detail: '/content.feedback/detail',
  edit: '/content.feedback/edit'
}

// 列表记录
export function list (params) {
  return axios({
    url: api.list,
    method: 'get',
    params
  })
}

// 详情信息
export function detail (params) {
  return axios({
    url: api.detail,
    method: 'get',
    params
  })
}

// 编辑处理
export function edit (data) {
  return axios({
    url: api.edit,
    method: 'post',
    data
  })
}
