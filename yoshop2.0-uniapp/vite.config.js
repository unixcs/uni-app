import path from 'path'
import { createRequire } from 'module'
import { defineConfig } from 'vite'

const require = createRequire(import.meta.url)

const projectRoot = process.cwd()
const isH5 = process.env.UNI_PLATFORM === 'h5'
const isHxCli = !!process.env.HX_CLI || !!process.env.HBUILDERX_PLUGINS || /HBuilderX/i.test(process.execPath || '')

global.uniPlugin = global.uniPlugin || {
  options: {},
  preprocess: {
    vueContext: {},
    nvueContext: {}
  },
  platforms: ['h5', 'web', 'mp-weixin', 'app-plus', 'mp-alipay', 'mp-baidu', 'mp-toutiao', 'mp-qq', 'mp-360'],
  configureH5: [],
  defaultTheme: 'light',
}

process.env.UNI_INPUT_DIR = process.env.UNI_INPUT_DIR || projectRoot
process.env.UNI_CLI_CONTEXT = process.env.UNI_CLI_CONTEXT || projectRoot
process.env.UNI_PLATFORM = process.env.UNI_PLATFORM || 'h5'

const { multipleEntryFilePlugin } = require('vite-plugin-multiple-entries2')
const uni = require('@dcloudio/vite-plugin-uni').default

/**
 * @type {import('vite').UserConfig}
 */
export default defineConfig({
  base: './',
  resolve: {
    alias: [
      {
        find: /^@dcloudio\/uni-h5\/dist\/uni-h5\.es\.js$/,
        replacement: path.resolve(projectRoot, './node_modules/@dcloudio/uni-h5/dist/uni-h5.es.js'),
      },
      {
        find: /^@dcloudio\/uni-h5$/,
        replacement: path.resolve(projectRoot, './node_modules/@dcloudio/uni-h5/dist/uni-h5.es.js'),
      },
      {
        find: /^@dcloudio\/uni-h5-vue\/dist\/vue\.runtime\.esm\.js$/,
        replacement: path.resolve(projectRoot, './node_modules/@dcloudio/uni-h5-vue/dist/vue.runtime.esm.js'),
      },
      {
        find: /^@dcloudio\/uni-h5-vue$/,
        replacement: path.resolve(projectRoot, './node_modules/@dcloudio/uni-h5-vue/dist/vue.runtime.esm.js'),
      },
    ]
  },
  build: {
    outDir: path.resolve(projectRoot, '../yoshop2.0/public'),
    emptyOutDir: false,
  },
  plugins: [
    uni(),
    ...((isH5 && !isHxCli) ? [multipleEntryFilePlugin({
      chunkName: 'config',
      entryPath: path.resolve(projectRoot, './config.js'),
      entryFileName: 'config.js',
      // injectTo: 'body-prepend',
      crossorigin: true,
    })] : []),
  ]
})
