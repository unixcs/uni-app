import request from '@/utils/request'

// api地址
const api = {
  create: 'feedback/create',
  list: 'feedback/list',
  detail: 'feedback/detail'
}

// 提交反馈/投诉
export const create = (data, option) => {
  return request.post(api.create, { form: data }, option)
}

// 反馈记录列表
export const list = (param, option) => {
  return request.get(api.list, param, option)
}

// 反馈详情
export const detail = (feedbackId, param, option) => {
  return request.get(api.detail, { feedbackId, ...param }, option)
}

