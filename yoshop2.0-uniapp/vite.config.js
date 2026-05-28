import path from 'path'
import { defineConfig } from 'vite'
import { multipleEntryFilePlugin } from 'vite-plugin-multiple-entries2'
import uni from '@dcloudio/vite-plugin-uni'

/**
 * @type {import('vite').UserConfig}
 */
export default defineConfig({
  plugins: [
    uni(),
    process.env.UNI_PLATFORM === 'h5' ? multipleEntryFilePlugin({
      chunkName: 'config',
      entryPath: path.resolve(__dirname, './config.js'),
      entryFileName: 'config.js',
      // injectTo: 'body-prepend',
      crossorigin: true,
    }) : undefined,
  ]
})