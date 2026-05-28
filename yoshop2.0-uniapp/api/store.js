import request from '@/utils/request'

// api地址
const api = {
  data: 'store/data'
}

// 获取商城基础信息
const data = () => {
  return request.get(api.data)
}

export default { data }
