import request from '@/utils/request'

// api地址
const api = {
  data: 'setting/data',
  privacyAgreement: 'setting/privacyAgreement'
}

// 设置项详情
export function data() {
  return request.get(api.data)
}

// 隐私协议内容
export function privacyAgreement(param, option) {
  return request.get(api.privacyAgreement, param, option)
}
