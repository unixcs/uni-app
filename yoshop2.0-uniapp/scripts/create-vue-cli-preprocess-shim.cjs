const fs = require('fs')
const path = require('path')

const file = path.resolve(__dirname, '../node_modules/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess.js')
fs.mkdirSync(path.dirname(file), { recursive: true })
fs.writeFileSync(file, "module.exports = { preprocess(content) { return content } }\n")
console.log(`[shim] created ${file}`)
