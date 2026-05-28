import { axios } from '@/utils/request'

// api接口列表
const api = {
  list: '/setting.payment.template/list',
  all: '/setting.payment.template/all',
  detail: '/setting.payment.template/detail',
  add: '/setting.payment.template/add',
  edit: '/setting.payment.template/edit',
  delete: '/setting.payment.template/delete'
}

// 列表记录
export function list (params) {
  return axios({
    url: api.list,
    method: 'get',
    params
  })
}

// 全部记录
export function all (params) {
  return axios({
    url: api.all,
    method: 'get',
    params
  })
}

/**
 * 详情信息
 * @param {int} templateId
 * @param {*} params
 */
export function detail (templateId, params) {
  return axios({
    url: api.detail,
    method: 'get',
    params: { templateId, ...params }
  })
}

/**
 * 新增记录
 * @param {*} data
 */
export function add (data) {
  return axios({
    headers: { 'Content-Type': 'multipart/form-data' },
    url: api.add,
    method: 'post',
    data
  })
}

/**
 * 编辑记录
 * @param {int} templateId
 * @param {*} data
 */
export function edit (data) {
  return axios({
    headers: { 'Content-Type': 'multipart/form-data' },
    url: api.edit,
    method: 'post',
    data
  })
}

/**
 * 删除记录
 * @param {*} data
 */
export function deleted (data) {
  return axios({
    url: api.delete,
    method: 'post',
    data
  })
}
