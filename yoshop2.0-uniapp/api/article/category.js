import request from '@/utils/request'

// api地址
const api = {
  list: 'article.category/list'
}

// 分类列表
export function list() {
  return request.get(api.list)
}
