const { spawn, spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const cliPath = '/mnt/d/Program/tools/HBuilderX/cli.exe'
const sourceRoot = '/opt/yoshop/yoshop2.0-uniapp'
const mirrorRoot = '/mnt/d/Program/0/home/0/yoshop1/yoshop2.0-uniapp'
const projectRoot = 'D:/Program/0/home/0/yoshop1/yoshop2.0-uniapp'
const compiledRoot = path.join(mirrorRoot, 'unpackage', 'dist', 'dev', 'mp-weixin')
const configPath = path.join(sourceRoot, 'config.js')
const mirrorConfigPath = path.join(mirrorRoot, 'config.js')
const releaseApiUrl = process.env.MP_WEIXIN_API_URL || ''
const compileTimeoutMs = Number(process.env.MP_WEIXIN_COMPILE_TIMEOUT_MS || 180000)
const compilePollMs = Number(process.env.MP_WEIXIN_COMPILE_POLL_MS || 2000)
const compileTargets = [
  path.join(compiledRoot, 'core', 'payment', 'wechat.js'),
  path.join(compiledRoot, 'pages', 'checkout', 'cashier', 'index.js'),
]
const staticSourceRoot = path.join(mirrorRoot, 'static')
const staticCompiledRoot = path.join(compiledRoot, 'static')

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

function syncCompiledStaticAssets() {
  if (!fs.existsSync(staticSourceRoot)) {
    throw new Error(`[build-mp-weixin] Missing source static directory: ${staticSourceRoot}`)
  }

  fs.mkdirSync(compiledRoot, { recursive: true })
  console.log('[build-mp-weixin] Syncing static assets into compiled mp-weixin output...')
  run('rsync', [
    '-a',
    '--delete',
    '--exclude', 'app-plus',
    `${staticSourceRoot}/`,
    `${staticCompiledRoot}/`,
  ], '/opt/yoshop')
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
    if (fs.existsSync(mirrorConfigPath)) {
      fs.writeFileSync(mirrorConfigPath, originalConfig, 'utf8')
      console.log('[build-mp-weixin] Restored Windows mirror config.js after release build.')
    }
  }
}

function readMtimeMs(filePath) {
  try {
    return fs.statSync(filePath).mtimeMs
  } catch (err) {
    return 0
  }
}

function readTargetMarker(filePath) {
  try {
    return fs.readFileSync(filePath, 'utf8')
  } catch (err) {
    return ''
  }
}

function artifactsLookFresh(baseline) {
  const [wechatCompiled, cashierCompiled] = compileTargets
  const wechatMtime = readMtimeMs(wechatCompiled)
  const cashierMtime = readMtimeMs(cashierCompiled)
  if (wechatMtime <= baseline[wechatCompiled] || cashierMtime <= baseline[cashierCompiled]) {
    return false
  }
  const wechatCode = readTargetMarker(wechatCompiled)
  const cashierCode = readTargetMarker(cashierCompiled)
  return wechatCode.includes('requestVirtualPayment')
    && wechatCode.includes('loginCode')
    && cashierCode.includes('resolveVirtualPaymentFailure')
    && cashierCode.includes('virtualPaymentLastFail')
}

async function launchCompileAndWait() {
  const baseline = Object.fromEntries(compileTargets.map(filePath => [filePath, readMtimeMs(filePath)]))
  const child = spawn(cliPath, [
    'launch',
    'mp-weixin',
    '--project',
    projectRoot,
    '--compile',
    'true',
    '--continue-on-error',
    'false',
  ], {
    cwd: '/opt/yoshop',
    stdio: 'inherit',
    shell: false,
  })

  let resolved = false
  const finish = success => {
    if (resolved) {
      return
    }
    resolved = true
    if (child.exitCode === null) {
      child.kill('SIGTERM')
    }
  }

  const exitPromise = new Promise((resolve, reject) => {
    child.once('error', reject)
    child.once('exit', code => {
      if (resolved) {
        resolve()
        return
      }
      if (code === 0) {
        resolve()
        return
      }
      reject(new Error(`[build-mp-weixin] HBuilderX CLI exited with code ${code}`))
    })
  })

  const readyPromise = new Promise((resolve, reject) => {
    const deadline = Date.now() + compileTimeoutMs
    const timer = setInterval(() => {
      if (artifactsLookFresh(baseline)) {
        clearInterval(timer)
        finish(true)
        resolve()
        return
      }
      if (Date.now() >= deadline) {
        clearInterval(timer)
        finish(false)
        reject(new Error(`[build-mp-weixin] Timed out after ${compileTimeoutMs}ms waiting for compiled mp-weixin artifacts to refresh`))
      }
    }, compilePollMs)
  })

  await Promise.race([exitPromise, readyPromise])
  if (!artifactsLookFresh(baseline)) {
    throw new Error('[build-mp-weixin] HBuilderX CLI returned before target mp-weixin artifacts were refreshed')
  }
}

withTemporaryApiUrlOverride(async () => {
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

  const openResult = spawnSync(cliPath, ['open', '--project', projectRoot], {
    cwd: '/opt/yoshop',
    stdio: 'inherit',
    shell: false,
  })

  if (openResult.status !== 0) {
    process.exit(openResult.status || 1)
  }

  const workdir = '/opt/yoshop'
  console.log('[build-mp-weixin] Launching HBuilderX compile and waiting for compiled artifacts to refresh...')
  process.chdir(workdir)
  await launchCompileAndWait()
  syncCompiledStaticAssets()
}).then(() => {
  const outputDir = path.win32.join('D:\\Program\\0\\home\\0\\yoshop1\\yoshop2.0-uniapp\\unpackage\\dist\\dev\\mp-weixin')
  console.log(`[build-mp-weixin] Compile finished. Output: ${outputDir}`)
}).catch(err => {
  console.error(err && err.stack ? err.stack : err)
  process.exit(1)
})
