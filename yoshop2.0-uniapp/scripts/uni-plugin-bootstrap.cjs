const path = require('path')

const inputDir = path.resolve(__dirname, '..')

global.uniPlugin = global.uniPlugin || {
  options: {},
  preprocess: {},
  platforms: ['h5', 'mp-weixin', 'app-plus', 'mp-alipay', 'mp-baidu', 'mp-toutiao', 'mp-qq', 'mp-360'],
  configureH5: [],
  defaultTheme: 'light',
}

process.env.UNI_INPUT_DIR = process.env.UNI_INPUT_DIR || inputDir
process.env.UNI_CLI_CONTEXT = process.env.UNI_CLI_CONTEXT || inputDir
process.env.UNI_PLATFORM = process.env.UNI_PLATFORM || 'h5'
