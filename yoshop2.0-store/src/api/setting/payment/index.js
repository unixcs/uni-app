import { axios } from '@/utils/request'

// api接口列表
const api = {
  options: '/setting.payment/options',
  update: '/setting.payment/update',
}

// 支付设置详情
export function options (params) {
  return axios({
    url: api.options,
    method: 'get',
    params
  })
}

/**
 * 更新设置
 * @param {*} data
 */
export function update (data) {
  return axios({
    url: api.update,
    method: 'post',
    data
  })
}
