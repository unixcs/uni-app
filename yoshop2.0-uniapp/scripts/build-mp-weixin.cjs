const { spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const cliPath = '/mnt/d/Program/tools/HBuilderX/cli.exe'
const sourceRoot = '/opt/yoshop/yoshop2.0-uniapp'
const mirrorRoot = '/mnt/d/Program/0/home/0/yoshop1/yoshop2.0-uniapp'
const projectRoot = 'D:/Program/0/home/0/yoshop1/yoshop2.0-uniapp'
const configPath = path.join(sourceRoot, 'config.js')
const releaseApiUrl = process.env.MP_WEIXIN_API_URL || ''

function run(command, args, cwd) {
  const result = spawnSync(command, args, {
    cwd,
    stdio: 'inherit',
    shell: false,
  })

  if (result.status !== 0) {
    process.exit(result.status || 1)
  }
}

function withTemporaryApiUrlOverride(fn) {
  if (!releaseApiUrl) {
    return fn()
  }

  const normalizedApiUrl = releaseApiUrl.endsWith('/') ? releaseApiUrl : `${releaseApiUrl}/`
  const originalConfig = fs.readFileSync(configPath, 'utf8')
  const apiUrlPattern = /(apiUrl:\s*["'])(.*?)(["'])/

  if (!apiUrlPattern.test(originalConfig)) {
    throw new Error(`[build-mp-weixin] Failed to locate apiUrl in ${configPath}`)
  }

  const nextConfig = originalConfig.replace(apiUrlPattern, `$1${normalizedApiUrl}$3`)

  if (nextConfig === originalConfig) {
    console.log('[build-mp-weixin] MP_WEIXIN_API_URL is set but config.js already matches the target apiUrl.')
    return fn()
  }

  fs.writeFileSync(configPath, nextConfig, 'utf8')
  console.log(`[build-mp-weixin] Temporarily overriding apiUrl for release build: ${normalizedApiUrl}`)

  try {
    return fn()
  } finally {
    fs.writeFileSync(configPath, originalConfig, 'utf8')
    console.log('[build-mp-weixin] Restored local config.js after release build.')
  }
}

withTemporaryApiUrlOverride(() => {
  console.log('[build-mp-weixin] Syncing WSL source to Windows mirror...')
  run('rsync', [
    '-a',
    '--delete',
    '--exclude', 'node_modules',
    '--exclude', 'dist',
    '--exclude', 'unpackage',
    '--exclude', '.git',
    '--exclude', '.vite',
    `${sourceRoot}/`,
    `${mirrorRoot}/`,
  ], '/opt/yoshop')

  const openResult = spawnSync(cliPath, ['open'], {
    cwd: '/opt/yoshop',
    stdio: 'inherit',
    shell: false,
  })

  if (openResult.status !== 0) {
    process.exit(openResult.status || 1)
  }

  const workdir = '/opt/yoshop'

  const result = spawnSync(cliPath, [
    'launch',
    'mp-weixin',
    '--project',
    projectRoot,
    '--compile',
    'true',
    '--continue-on-error',
    'false',
  ], {
    cwd: workdir,
    stdio: 'inherit',
    shell: false,
  })

  if (result.status !== 0) {
    process.exit(result.status || 1)
  }

  const outputDir = path.win32.join('D:\\Program\\0\\home\\0\\yoshop1\\yoshop2.0-uniapp\\unpackage\\dist\\dev\\mp-weixin')
  console.log(`[build-mp-weixin] Compile finished. Output: ${outputDir}`)
})
