import api from '../api.config'
import { axios } from '@/utils/request'

/**
 * 测试队列服务是否开启
 * @param {*} data
 */
export function test (data) {
  return axios({
    url: api.setting.queue.test,
    method: 'post',
    data
  })
}
