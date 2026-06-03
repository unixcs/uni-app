const fs = require('fs')
const path = require('path')

const distDir = path.resolve(__dirname, '../node_modules/@dcloudio/uni-cli-shared/dist')
const indexFile = path.join(distDir, 'index.js')

fs.mkdirSync(distDir, { recursive: true })
fs.writeFileSync(indexFile, `const fs = require('fs')
const path = require('path')

const lib = require('../lib')

lib.parseCompatConfigOnce = lib.parseCompatConfigOnce || function parseCompatConfigOnce () {
  return { MODE: 0 }
}

lib.resolveBuiltIn = lib.resolveBuiltIn || function resolveBuiltIn (id) {
  return id
}

lib.EXTNAME_VUE_RE = lib.EXTNAME_VUE_RE || /\\.vue$/

lib.parsePagesJsonOnce = lib.parsePagesJsonOnce || function parsePagesJsonOnce () {
  const file = path.resolve(process.env.UNI_INPUT_DIR || process.cwd(), 'pages.json')
  const content = fs.readFileSync(file, 'utf8')
  const json = lib.parsePagesJson(content)
  json.globalStyle = json.globalStyle || {}
  json.globalStyle.navigationBar = json.globalStyle.navigationBar || { style: 'default' }
  json.pages = Array.isArray(json.pages) ? json.pages.map(page => {
    page.style = page.style || {}
    page.style.navigationBar = page.style.navigationBar || { style: 'default', buttons: [] }
    return page
  }) : []
  return json
}

lib.parseManifestJsonOnce = lib.parseManifestJsonOnce || function parseManifestJsonOnce () {
  const file = path.resolve(process.env.UNI_INPUT_DIR || process.cwd(), 'manifest.json')
  const content = fs.readFileSync(file, 'utf8')
  return lib.parseManifestJson(content)
}

module.exports = lib
`)

console.log(`[shim] created ${indexFile}`)
