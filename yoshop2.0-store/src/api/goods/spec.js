import { axios } from '@/utils/request'

// api接口列表
const api = {
  list: '/goods.spec/list'
}

// 列表记录
export function list (goodsId, params) {
  return axios({
    url: api.list,
    method: 'get',
    params: { goodsId, params }
  })
}
