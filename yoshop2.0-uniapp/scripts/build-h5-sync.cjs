const { spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const projectRoot = path.resolve(__dirname, '..')
const h5OutputDir = path.resolve(projectRoot, 'dist/build/h5')
const publicDir = path.resolve(projectRoot, '../yoshop2.0/public')

function run(command, args, cwd = projectRoot) {
  const result = spawnSync(command, args, {
    cwd,
    stdio: 'inherit',
    shell: false,
  })
  if (result.status !== 0) {
    process.exit(result.status || 1)
  }
}

function copyRecursive(src, dest) {
  fs.cpSync(src, dest, { recursive: true, force: true })
}

function ensureExists(target, label) {
  if (!fs.existsSync(target)) {
    console.error(`[build-h5-sync] Missing ${label}: ${target}`)
    process.exit(1)
  }
}

run('npm', ['run', 'build:h5'])

ensureExists(h5OutputDir, 'H5 output directory')
ensureExists(publicDir, 'public directory')

const assetsSrc = path.join(h5OutputDir, 'assets')
const assetsDest = path.join(publicDir, 'assets')
const indexSrc = path.join(h5OutputDir, 'index.html')
const configSrc = path.join(h5OutputDir, 'config.js')
const indexDest = path.join(publicDir, 'index.html')
const configDest = path.join(publicDir, 'config.js')

ensureExists(assetsSrc, 'H5 assets directory')
ensureExists(indexSrc, 'H5 index.html')
ensureExists(configSrc, 'H5 config.js')

if (fs.existsSync(assetsDest)) {
  fs.rmSync(assetsDest, { recursive: true, force: true })
}

copyRecursive(assetsSrc, assetsDest)
fs.copyFileSync(indexSrc, indexDest)
fs.copyFileSync(configSrc, configDest)

console.log('[build-h5-sync] Synced H5 output to public successfully.')
