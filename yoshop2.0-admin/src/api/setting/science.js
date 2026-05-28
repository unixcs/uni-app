import api from '../api.config'
import { axios } from '@/utils/request'

/**
 * 清理缓存api
 * @param {*} data
 */
export function info () {
  return axios({
    url: api.setting.science,
    method: 'get'
  })
}
