import config from '@/config.js'
import defaultConfig from './defaultConfig.js'

// 合并用户配置和默认配置
const options = Object.assign({}, defaultConfig, config)

/**
 * 配置文件工具类
 * @module Config
 * fix: 如需在项目中获取配置项, 请使用本工具类的方法, 不要直接import根目录的config.js
 */
export default {

  /**
   * 获取全部配置
   */
  all() {
    return options
  },

  /**
   * 获取指定配置
   * @param {string} key
   * @param {mixed} def
   */
  get(key, def = undefined) {
    if (options.hasOwnProperty(key)) {
      return options[key]
    }
    console.error(`检测到不存在的配置项: ${key}`)
    return def
  }

}
