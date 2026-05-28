import api from '../api.config'
import { axios } from '@/utils/request'

/**
 * 测试定时任务是否开启
 * @param {*} data
 */
export function test (data) {
  return axios({
    url: api.setting.timer.test,
    method: 'post',
    data
  })
}
