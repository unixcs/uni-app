const { spawn, spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const API_URLS = Object.freeze({
  test: 'https://wx.oiob.cn/index.php?s=/api/',
  production: 'https://wx.gxwqb.cn/index.php?s=/api/',
})
const MIRROR_SENTINEL = '.yoshop-windows-mirror.json'

function parseEnvironment(argv = process.argv.slice(2)) {
  const position = argv.indexOf('--environment')
  const value = position >= 0 ? argv[position + 1] : 'test'
  if (!Object.prototype.hasOwnProperty.call(API_URLS, value)) {
    throw new Error(`[build-mp-weixin] --environment must be one of: ${Object.keys(API_URLS).join(', ')}`)
  }
  return value
}

function normalizedUrl(value) {
  return value.endsWith('/') ? value : `${value}/`
}

function assertSafeMirrorRoot(sourceRoot, mirrorRoot, projectName) {
  const sourceReal = fs.realpathSync(sourceRoot)
  const mirrorReal = fs.realpathSync(mirrorRoot)
  if (sourceReal === mirrorReal || mirrorReal === '/' || mirrorReal.length < 20) {
    throw new Error(`[build-mp-weixin] Refusing unsafe mirror path: ${mirrorReal}`)
  }
  if (!mirrorReal.startsWith('/mnt/')) {
    throw new Error(`[build-mp-weixin] Windows mirror must resolve below /mnt/: ${mirrorReal}`)
  }
  const sentinelPath = path.join(mirrorReal, MIRROR_SENTINEL)
  if (!fs.existsSync(sentinelPath)) {
    throw new Error(`[build-mp-weixin] Missing mirror sentinel: ${sentinelPath}`)
  }
  let sentinel
  try {
    sentinel = JSON.parse(fs.readFileSync(sentinelPath, 'utf8'))
  } catch (err) {
    throw new Error(`[build-mp-weixin] Invalid mirror sentinel JSON: ${sentinelPath}`)
  }
  if (sentinel.kind !== 'yoshop-windows-mirror' || sentinel.projectName !== projectName) {
    throw new Error(`[build-mp-weixin] Mirror sentinel does not identify ${projectName}`)
  }
  return mirrorReal
}

function run(command, args, cwd) {
  const result = spawnSync(command, args, { cwd, stdio: 'inherit', shell: false })
  if (result.status !== 0) {
    throw new Error(`[build-mp-weixin] Command failed (${result.status || 1}): ${command}`)
  }
}

async function withTemporaryApiUrlOverride(configPaths, apiUrl, fn) {
  const normalizedApiUrl = normalizedUrl(apiUrl)
  const snapshots = []
  const apiUrlPattern = /(apiUrl:\s*["'])(.*?)(["'])/

  for (const configPath of configPaths) {
    if (!fs.existsSync(configPath)) continue
    const original = fs.readFileSync(configPath, 'utf8')
    if (!apiUrlPattern.test(original)) {
      throw new Error(`[build-mp-weixin] Failed to locate apiUrl in ${configPath}`)
    }
    snapshots.push([configPath, original])
    fs.writeFileSync(configPath, original.replace(apiUrlPattern, `$1${normalizedApiUrl}$3`), 'utf8')
  }

  console.log(`[build-mp-weixin] Temporarily using ${normalizedApiUrl}`)
  try {
    return await fn()
  } finally {
    for (const [configPath, original] of snapshots) {
      fs.writeFileSync(configPath, original, 'utf8')
    }
    console.log('[build-mp-weixin] Restored local and mirror config.js files.')
  }
}

function readMtimeMs(filePath) {
  try { return fs.statSync(filePath).mtimeMs } catch (err) { return 0 }
}

function readText(filePath) {
  try { return fs.readFileSync(filePath, 'utf8') } catch (err) { return '' }
}

function artifactsLookFresh(compileTargets, baseline) {
  const [wechatCompiled, cashierCompiled] = compileTargets
  if (readMtimeMs(wechatCompiled) <= baseline[wechatCompiled]
      || readMtimeMs(cashierCompiled) <= baseline[cashierCompiled]) return false
  const wechatCode = readText(wechatCompiled)
  const cashierCode = readText(cashierCompiled)
  return wechatCode.includes('requestVirtualPayment')
    && wechatCode.includes('loginCode')
    && cashierCode.includes('resolveVirtualPaymentFailure')
    && cashierCode.includes('virtualPaymentLastFail')
}

async function launchCompileAndWait(options) {
  const { cliPath, projectName, compileTargets, compileTimeoutMs, compilePollMs, cwd } = options
  const baseline = Object.fromEntries(compileTargets.map(filePath => [filePath, readMtimeMs(filePath)]))
  const child = spawn(cliPath, [
    'launch', 'mp-weixin', '--project', projectName, '--compile', 'true',
    '--continue-on-error', 'false',
  ], { cwd, stdio: 'inherit', shell: false })

  let settled = false
  const stopChild = () => {
    if (settled) return
    settled = true
    if (child.exitCode === null) child.kill('SIGTERM')
  }
  const exitPromise = new Promise((resolve, reject) => {
    child.once('error', reject)
    child.once('exit', code => {
      if (settled || code === 0) resolve()
      else reject(new Error(`[build-mp-weixin] HBuilderX CLI exited with code ${code}`))
    })
  })
  const readyPromise = new Promise((resolve, reject) => {
    const deadline = Date.now() + compileTimeoutMs
    const timer = setInterval(() => {
      if (artifactsLookFresh(compileTargets, baseline)) {
        clearInterval(timer); stopChild(); resolve()
      } else if (Date.now() >= deadline) {
        clearInterval(timer); stopChild()
        reject(new Error(`[build-mp-weixin] Timed out after ${compileTimeoutMs}ms`))
      }
    }, compilePollMs)
  })
  await Promise.race([exitPromise, readyPromise])
  if (!artifactsLookFresh(compileTargets, baseline)) {
    throw new Error('[build-mp-weixin] HBuilderX returned before artifacts refreshed')
  }
}

function verifyCompiledDomain(compiledRoot, expectedUrl) {
  const configPath = path.join(compiledRoot, 'config.js')
  const text = readText(configPath)
  const expected = normalizedUrl(expectedUrl)
  if (!text.includes(expected)) {
    throw new Error(`[build-mp-weixin] Compiled config does not contain ${expected}`)
  }
  for (const candidate of Object.values(API_URLS)) {
    if (candidate !== expected && text.includes(candidate)) {
      throw new Error(`[build-mp-weixin] Compiled config contains wrong environment URL: ${candidate}`)
    }
  }
}

async function main() {
  const environment = parseEnvironment()
  const projectName = process.env.YOSHOP_HBUILDER_PROJECT_NAME || 'yoshop2.0-uniapp'
  const sourceRoot = process.env.YOSHOP_SOURCE_ROOT || '/opt/yoshop/yoshop2.0-uniapp'
  const mirrorRootInput = process.env.YOSHOP_WINDOWS_MIRROR_ROOT || '/mnt/d/Program/0/home/0/yoshop1/yoshop2.0-uniapp'
  const projectRoot = process.env.YOSHOP_HBUILDER_PROJECT_ROOT || 'D:/Program/0/home/0/yoshop1/yoshop2.0-uniapp'
  const cliPath = process.env.HBUILDERX_CLI || '/mnt/d/Program/tools/HBuilderX/cli.exe'
  const apiUrl = normalizedUrl(process.env.MP_WEIXIN_API_URL || API_URLS[environment])
  const mirrorRoot = assertSafeMirrorRoot(sourceRoot, mirrorRootInput, projectName)
  const compiledRoot = path.join(mirrorRoot, 'unpackage', 'dist', 'dev', 'mp-weixin')
  const configPath = path.join(sourceRoot, 'config.js')
  const mirrorConfigPath = path.join(mirrorRoot, 'config.js')
  const compileTargets = [
    path.join(compiledRoot, 'core', 'payment', 'wechat.js'),
    path.join(compiledRoot, 'pages', 'checkout', 'cashier', 'index.js'),
  ]

  await withTemporaryApiUrlOverride([configPath, mirrorConfigPath], apiUrl, async () => {
    console.log('[build-mp-weixin] Syncing guarded WSL source to Windows mirror...')
    run('rsync', [
      '-a', '--delete',
      '--exclude', 'node_modules', '--exclude', 'dist', '--exclude', 'unpackage',
      '--exclude', '.git', '--exclude', '.vite', '--exclude', MIRROR_SENTINEL,
      `${sourceRoot}/`, `${mirrorRoot}/`,
    ], sourceRoot)
    // The synchronized mirror now contains the environment-specific config.
    run(cliPath, ['open', '--project', projectRoot], sourceRoot)
    console.log('[build-mp-weixin] Launching HBuilderX compile...')
    await launchCompileAndWait({
      cliPath, projectName, compileTargets,
      compileTimeoutMs: Number(process.env.MP_WEIXIN_COMPILE_TIMEOUT_MS || 180000),
      compilePollMs: Number(process.env.MP_WEIXIN_COMPILE_POLL_MS || 2000),
      cwd: sourceRoot,
    })
    const staticSourceRoot = path.join(mirrorRoot, 'static')
    if (!fs.existsSync(staticSourceRoot)) throw new Error(`Missing static directory: ${staticSourceRoot}`)
    run('rsync', [
      '-a', '--delete', '--exclude', 'app-plus',
      `${staticSourceRoot}/`, `${path.join(compiledRoot, 'static')}/`,
    ], sourceRoot)
    verifyCompiledDomain(compiledRoot, apiUrl)
  })

  // Source and mirror config restoration occurs only after the awaited compile.
  // The compiled config remains environment-specific.
  console.log(`[build-mp-weixin] ${environment} build passed: ${compiledRoot}`)
}

if (require.main === module) {
  main().catch(err => {
    console.error(err && err.stack ? err.stack : err)
    process.exit(1)
  })
}

module.exports = {
  API_URLS,
  MIRROR_SENTINEL,
  assertSafeMirrorRoot,
  parseEnvironment,
  verifyCompiledDomain,
  withTemporaryApiUrlOverride,
}
