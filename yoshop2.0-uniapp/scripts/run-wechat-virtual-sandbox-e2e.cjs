const fs = require('fs')
const http = require('http')
const https = require('https')
const path = require('path')
const { URL } = require('url')
const { spawnSync } = require('child_process')

const automator = require(process.env.MINIPROGRAM_AUTOMATOR_MODULE || '/opt/store/node_modules/miniprogram-automator')

const sourceConfigPath = '/opt/yoshop/yoshop2.0-uniapp/config.js'
const mirrorConfigPath = '/mnt/d/Program/0/home/0/yoshop1/yoshop2.0-uniapp/config.js'
const compiledConfigPath = '/mnt/d/Program/0/home/0/yoshop1/yoshop2.0-uniapp/unpackage/dist/dev/mp-weixin/config.js'
const workspaceApiUrl = readApiUrlFromConfig(sourceConfigPath)
const apiBase = normalizeApiBase(process.env.WECHAT_VIRTUAL_API_BASE || workspaceApiUrl || 'http://localhost')
const expectedApiUrl = `${apiBase}/index.php?s=/api/`
const orderPlatform = process.env.WECHAT_VIRTUAL_ORDER_PLATFORM || 'MP-WEIXIN'
const debugMobile = String(process.env.WECHAT_VIRTUAL_DEBUG_MOBILE || '19900000000')
const goodsId = Number(process.env.WECHAT_VIRTUAL_GOODS_ID || 10001)
const goodsSkuId = String(process.env.WECHAT_VIRTUAL_GOODS_SKU_ID || '0')
const wsPort = Number(process.env.WECHAT_AUTOMATOR_PORT || 19420)
const pollMs = Number(process.env.WECHAT_VIRTUAL_POLL_MS || 3000)
const pollLimit = Number(process.env.WECHAT_VIRTUAL_POLL_LIMIT || 20)
const windowsCmd = process.env.WECHAT_WINDOWS_CMD || '/mnt/c/Windows/System32/cmd.exe'
const wechatCliWin = process.env.WECHAT_DEVTOOLS_CLI_WIN || 'D:\\Program\\soft\\wechattools\\cli.bat'
const projectPathWin = process.env.WECHAT_DEVTOOLS_PROJECT_WIN || 'D:\\Program\\0\\home\\0\\yoshop1\\yoshop2.0-uniapp\\unpackage\\dist\\dev\\mp-weixin'
const devtoolsPort = String(process.env.WECHAT_DEVTOOLS_PORT || '').trim()
const devtoolsBridgePort = String(process.env.WECHAT_DEVTOOLS_BRIDGE_PORT || '3909').trim()
const shouldBuildMp = process.env.WECHAT_VIRTUAL_BUILD_MP === '1'
const shouldHardResetDevtools = process.env.WECHAT_DEVTOOLS_HARD_RESET !== '0'
const shouldReopenManualDevtools = process.env.WECHAT_DEVTOOLS_REOPEN_MANUAL !== '0'
const runId = `sandbox-${Date.now()}`
const evidencePath = process.env.WECHAT_VIRTUAL_EVIDENCE_PATH || `/opt/yoshop/runtime/service-order-e2e/wechat-virtual-sandbox-${runId}.json`

process.env.WECHAT_DEVTOOLS_BRIDGE_PORT = devtoolsBridgePort

function trimTrailingSlash(value) {
  return value.endsWith('/') ? value.slice(0, -1) : value
}

function normalizeApiBase(value) {
  const normalizedValue = trimTrailingSlash(String(value || '').trim())
  return normalizedValue.replace(/\/index\.php\?s=\/api$/i, '')
}

function readApiUrlFromConfig(configFile) {
  try {
    const content = fs.readFileSync(configFile, 'utf8')
    const match = content.match(/apiUrl:\s*["'](.*?)["']/)
    return match ? match[1] : ''
  } catch (err) {
    return ''
  }
}

function ensureApiAlignment() {
  const checks = [
    { label: 'workspace', file: sourceConfigPath, apiUrl: readApiUrlFromConfig(sourceConfigPath) },
    { label: 'windows-mirror', file: mirrorConfigPath, apiUrl: readApiUrlFromConfig(mirrorConfigPath) },
    { label: 'compiled-mp-weixin', file: compiledConfigPath, apiUrl: readApiUrlFromConfig(compiledConfigPath) }
  ]
  const mismatches = checks.filter(item => item.apiUrl && item.apiUrl !== expectedApiUrl)
  if (mismatches.length > 0) {
    const detail = mismatches.map(item => `${item.label}=${item.apiUrl} (${item.file})`).join('; ')
    throw new Error(`API alignment mismatch: expected ${expectedApiUrl}; ${detail}`)
  }
  const missing = checks.filter(item => !item.apiUrl)
  if (missing.length > 0) {
    const detail = missing.map(item => `${item.label} missing apiUrl (${item.file})`).join('; ')
    throw new Error(`API alignment preflight failed: ${detail}`)
  }
  return checks
}

function buildApiUrl(route) {
  const normalizedRoute = route.startsWith('/') ? route.slice(1) : route
  return `${apiBase}/index.php?s=/api/${normalizedRoute}`
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

function writeEvidence(payload) {
  try {
    fs.mkdirSync(path.dirname(evidencePath), { recursive: true })
    fs.writeFileSync(evidencePath, JSON.stringify(payload, null, 2))
  } catch (err) {
    // best effort only
  }
}

function withTimeout(promise, ms, label) {
  let timer = null
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`${label} timeout after ${ms}ms`)), ms)
  })
  return Promise.race([promise, timeout]).finally(() => {
    if (timer) {
      clearTimeout(timer)
    }
  })
}

function requestJson(rawUrl, { method = 'GET', headers = {}, body } = {}) {
  const url = new URL(rawUrl)
  const transport = url.protocol === 'https:' ? https : http
  const payload = body === undefined ? null : JSON.stringify(body)

  return new Promise((resolve, reject) => {
    const req = transport.request({
      protocol: url.protocol,
      hostname: url.hostname,
      port: url.port || undefined,
      path: `${url.pathname}${url.search}`,
      method,
      headers: {
        Accept: 'application/json',
        ...headers,
        ...(payload
          ? {
              'Content-Type': 'application/json',
              'Content-Length': Buffer.byteLength(payload)
            }
          : {})
      }
    }, res => {
      let raw = ''
      res.setEncoding('utf8')
      res.on('data', chunk => { raw += chunk })
      res.on('end', () => {
        let data = null
        try {
          data = raw ? JSON.parse(raw) : null
        } catch (err) {
          reject(new Error(`Invalid JSON from ${rawUrl}: ${raw}`))
          return
        }
        resolve({
          statusCode: res.statusCode || 0,
          headers: res.headers,
          data
        })
      })
    })
    req.on('error', reject)
    if (payload) {
      req.write(payload)
    }
    req.end()
  })
}

function hasDevtoolsInitializeError(output) {
  return /#initialize-error/i.test(String(output || ''))
}

function runCommand(command, args, cwd) {
  const result = spawnSync(command, args, {
    cwd,
    stdio: 'pipe',
    encoding: 'utf8'
  })
  const output = [result.stdout, result.stderr].filter(Boolean).join('\n').trim()
  if (result.status !== 0) {
    throw new Error(output || `${command} failed with status ${result.status}`)
  }
  if (hasDevtoolsInitializeError(output)) {
    throw new Error(output)
  }
  return result.stdout.trim()
}

function runCommandAllowFailure(command, args, cwd) {
  const result = spawnSync(command, args, {
    cwd,
    stdio: 'pipe',
    encoding: 'utf8'
  })
  return {
    status: result.status,
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim(),
    initializeError: hasDevtoolsInitializeError([result.stdout, result.stderr].filter(Boolean).join('\n'))
  }
}

function ensureMiniProgramBuild() {
  if (!shouldBuildMp) {
    return
  }
  runCommand('npm', ['run', 'build:mp-weixin'], '/opt/yoshop/yoshop2.0-uniapp')
}

function buildDevtoolsCliCommand(args) {
  const segments = [wechatCliWin]
  if (devtoolsPort) {
    segments.push(`--port ${devtoolsPort}`)
  }
  segments.push(args)
  return segments.join(' ')
}

function buildDevtoolsCliFromDir(args) {
  return `cd /d D:\\Program\\soft\\wechattools && ${buildDevtoolsCliCommand(args)}`
}

function ensureDevtoolsCleanSlate() {
  if (!shouldHardResetDevtools) {
    return []
  }
  const actions = []
  const cacheTypes = ['session', 'storage', 'network', 'compile']

  actions.push({
    step: 'close-project',
    ...runCommandAllowFailure(windowsCmd, [
      '/c',
      buildDevtoolsCliFromDir(`close --project ${projectPathWin}`)
    ], '/mnt/c')
  })
  actions.push({
    step: 'quit-ide',
    ...runCommandAllowFailure(windowsCmd, [
      '/c',
      buildDevtoolsCliFromDir('quit')
    ], '/mnt/c')
  })
  actions.push({
    step: 'taskkill-wechatdevtools',
    ...runCommandAllowFailure(windowsCmd, ['/c', 'taskkill /IM wechatdevtools.exe /F /T'], '/mnt/c')
  })
  actions.push({
    step: 'taskkill-wechatwebdevtools',
    ...runCommandAllowFailure(windowsCmd, ['/c', 'taskkill /IM wechatwebdevtools.exe /F /T'], '/mnt/c')
  })
  actions.push({
    step: 'taskkill-WeChatAppEx',
    ...runCommandAllowFailure(windowsCmd, ['/c', 'taskkill /IM WeChatAppEx.exe /F /T'], '/mnt/c')
  })
  for (const cacheType of cacheTypes) {
    actions.push({
      step: `cache-${cacheType}`,
      ...runCommandAllowFailure(windowsCmd, [
        '/c',
        buildDevtoolsCliFromDir(`cache --clean ${cacheType} --project ${projectPathWin}`)
      ], '/mnt/c')
    })
  }
  return actions
}

function ensureDevtoolsAutomation() {
  runCommand(windowsCmd, [
    '/c',
    buildDevtoolsCliCommand(`auto --project ${projectPathWin} --auto-port ${wsPort} --trust-project`)
  ], '/mnt/c')
}

function restoreManualDevtoolsSession() {
  const actions = []
  actions.push({
    step: 'close-project',
    ...runCommandAllowFailure(windowsCmd, [
      '/c',
      buildDevtoolsCliFromDir(`close --project ${projectPathWin}`)
    ], '/mnt/c')
  })
  actions.push({
    step: 'quit-ide',
    ...runCommandAllowFailure(windowsCmd, [
      '/c',
      buildDevtoolsCliFromDir('quit')
    ], '/mnt/c')
  })
  actions.push({
    step: 'taskkill-wechatdevtools',
    ...runCommandAllowFailure(windowsCmd, ['/c', 'taskkill /IM wechatdevtools.exe /F /T'], '/mnt/c')
  })
  actions.push({
    step: 'taskkill-wechatwebdevtools',
    ...runCommandAllowFailure(windowsCmd, ['/c', 'taskkill /IM wechatwebdevtools.exe /F /T'], '/mnt/c')
  })
  actions.push({
    step: 'taskkill-WeChatAppEx',
    ...runCommandAllowFailure(windowsCmd, ['/c', 'taskkill /IM WeChatAppEx.exe /F /T'], '/mnt/c')
  })
  if (shouldReopenManualDevtools) {
    actions.push({
      step: 'open-project-manual',
      ...runCommandAllowFailure(windowsCmd, [
        '/c',
        buildDevtoolsCliFromDir(`open --project ${projectPathWin}`)
      ], '/mnt/c')
    })
  }
  return actions
}

function createLocalDebugIdentity() {
  const php = [
    'require __DIR__ . "/vendor/autoload.php";',
    '$app = new think\\App();',
    '$app->initialize();',
    '$service = new app\\api\\service\\passport\\Login();',
    'if (!$service->loginDebug()) { fwrite(STDERR, ($service->getError() ?: "loginDebug failed") . PHP_EOL); exit(1); }',
    'echo json_encode(["token"=>$service->getToken((int)$service->getUserInfo()["user_id"]), "userId"=>(int)$service->getUserInfo()["user_id"]], JSON_UNESCAPED_UNICODE);'
  ].join(' ')
  const stdout = runCommand('php', ['-r', php], '/opt/yoshop/yoshop2.0')
  const data = JSON.parse(stdout || '{}')
  if (!data.token || !data.userId) {
    throw new Error(`createLocalDebugIdentity failed: ${stdout}`)
  }
  return data
}

async function createServiceOrder(token, scenario) {
  const response = await requestJson(`${buildApiUrl('checkout/submit')}&mode=buyNow`, {
    method: 'POST',
    headers: {
      platform: orderPlatform,
      'Access-Token': token
    },
    body: {
      goodsId,
      goodsSkuId,
      goodsNum: 1,
      scene: 'service',
      contactName: 'Wechat Virtual Sandbox',
      contactMobile: debugMobile,
      timePreference: 'anytime',
      remark: `E2E_REAL:${runId}:${scenario}`
    }
  })
  const orderId = Number(response.data?.data?.orderId || 0)
  if (response.statusCode !== 200 || response.data?.status !== 200 || orderId <= 0) {
    throw new Error(`createServiceOrder failed: ${JSON.stringify(response.data || response)}`)
  }
  return orderId
}

async function getOrderInfo(orderId, token) {
  const response = await requestJson(`${buildApiUrl('cashier/orderInfo')}&orderId=${orderId}&client=MP-WEIXIN`, {
    headers: {
      platform: 'MP-WEIXIN',
      'Access-Token': token
    }
  })
  if (response.statusCode !== 200 || response.data?.status !== 200) {
    throw new Error(`orderInfo failed: ${JSON.stringify(response.data || response)}`)
  }
  return response.data.data
}

async function orderPay(orderId, token) {
  const response = await requestJson(buildApiUrl('cashier/orderPay'), {
    method: 'POST',
    headers: {
      platform: 'MP-WEIXIN',
      'Access-Token': token
    },
    body: {
      orderId,
      method: 'wechat',
      client: 'MP-WEIXIN',
      extra: {}
    }
  })
  if (response.statusCode !== 200 || response.data?.status !== 200) {
    throw new Error(`orderPay failed: ${JSON.stringify(response.data || response)}`)
  }
  return response.data.data.payment
}

async function tradeQuery(outTradeNo, token) {
  const response = await requestJson(
    `${buildApiUrl('cashier/tradeQuery')}&outTradeNo=${encodeURIComponent(outTradeNo)}&method=wechat&client=MP-WEIXIN`,
    {
      headers: {
        platform: 'MP-WEIXIN',
        'Access-Token': token
      }
    }
  )
  return response.data || response
}

async function orderDetail(orderId, token) {
  const response = await requestJson(`${buildApiUrl('order/detail')}&orderId=${orderId}`, {
    headers: {
      platform: 'MP-WEIXIN',
      'Access-Token': token
    }
  })
  return response.data || response
}

async function connectMiniProgram() {
  return automator.connect({ wsEndpoint: `ws://127.0.0.1:${wsPort}` })
}

async function injectLoginState(miniProgram, token, userId) {
  return miniProgram.evaluate((nextToken, nextUserId) => {
    wx.setStorageSync('AccessToken', nextToken)
    wx.setStorageSync('userId', nextUserId)
    const pages = getCurrentPages()
    const page = pages.length ? pages[pages.length - 1] : null
    const app = getApp()
    const applyStore = target => {
      if (target && target.$store) {
        target.$store.commit('SET_TOKEN', nextToken)
        target.$store.commit('SET_USER_ID', nextUserId)
        return true
      }
      return false
    }
    const applied = applyStore(app && app.$vm) || applyStore(page && page.$vm) || applyStore(page)
    return {
      accessToken: wx.getStorageSync('AccessToken'),
      userId: wx.getStorageSync('userId'),
      applied
    }
  }, token, userId)
}

function sanitizePayment(payment) {
  if (!payment) {
    return null
  }
  return {
    provider: payment.provider,
    platform: payment.platform,
    outTradeNo: payment.outTradeNo || payment.out_trade_no,
    mode: payment.mode,
    signData: payment.signData,
    hasPaySig: Boolean(payment.paySig),
    hasSignature: Boolean(payment.signature)
  }
}

async function getCurrentCashierVmState(miniProgram) {
  return miniProgram.evaluate(() => {
    const pages = getCurrentPages()
    const page = pages.length ? pages[pages.length - 1] : null
    const vm = page && page.$vm
    let systemInfo = null
    try {
      systemInfo = typeof wx !== 'undefined' && typeof wx.getSystemInfoSync === 'function'
        ? wx.getSystemInfoSync()
        : null
    } catch (err) {
      systemInfo = { error: err && err.message ? err.message : String(err) }
    }
    return {
      route: page && (page.route || page.__route__),
      hasVm: Boolean(vm),
      platform: vm ? vm.platform : null,
      isLoading: vm ? vm.isLoading : null,
      disabled: vm ? vm.disabled : null,
      curPaymentMethod: vm && vm.curPaymentItem ? vm.curPaymentItem.method : null,
      methods: vm && Array.isArray(vm.methods) ? vm.methods.map(item => item.method) : [],
      hasHandleSubmit: Boolean(vm && typeof vm.handleSubmit === 'function'),
      systemInfo
    }
  })
}

async function waitForCashierReady(miniProgram, timeoutMs = 30000, intervalMs = 1000) {
  const startedAt = Date.now()
  let latest = null
  while ((Date.now() - startedAt) < timeoutMs) {
    latest = await getCurrentCashierVmState(miniProgram)
    const methods = Array.isArray(latest && latest.methods) ? latest.methods : []
    const currentMethod = latest && latest.curPaymentMethod
    const isReady = latest
      && latest.hasVm
      && latest.isLoading === false
      && methods.length > 0
      && currentMethod
    if (isReady) {
      return latest
    }
    await sleep(intervalMs)
  }
  throw new Error(`cashier not ready after ${timeoutMs}ms: ${JSON.stringify(latest || null)}`)
}

async function triggerCashierSubmit(miniProgram) {
  return miniProgram.evaluate(() => {
    const pages = getCurrentPages()
    const page = pages.length ? pages[pages.length - 1] : null
    const vm = page && page.$vm
    if (!vm) {
      return { ok: false, reason: 'cashier vm missing' }
    }
    if (typeof vm.handleSubmit !== 'function') {
      return { ok: false, reason: 'cashier handleSubmit missing' }
    }
    vm.handleSubmit()
    return {
      ok: true,
      route: page && (page.route || page.__route__),
      curPaymentMethod: vm.curPaymentItem ? vm.curPaymentItem.method : null
    }
  })
}

async function installVirtualPaymentObserver(miniProgram) {
  return miniProgram.evaluate(() => {
    if (typeof wx === 'undefined' || typeof wx.requestVirtualPayment !== 'function') {
      return { ok: false, reason: 'wx.requestVirtualPayment missing' }
    }
    if (globalThis.__virtualPaymentObserverInstalled) {
      return { ok: true, reused: true }
    }
    const original = wx.requestVirtualPayment
    try {
      wx.requestVirtualPayment = function wrappedVirtualPayment(options = {}) {
        const marker = {
          triggeredAt: Date.now(),
          mode: options.mode || '',
          signData: options.signData || '',
          paySig: options.paySig || '',
          signature: options.signature || '',
          queued: false
        }
        globalThis.__virtualPaymentNativeCall = marker
        const nextOptions = {
          ...options,
          success(res) {
            marker.success = res
            marker.finishedAt = Date.now()
            if (typeof options.success === 'function') {
              options.success(res)
            }
          },
          fail(err) {
            marker.fail = err
            marker.finishedAt = Date.now()
            if (typeof options.fail === 'function') {
              options.fail(err)
            }
          },
          complete(res) {
            marker.complete = res
            marker.finishedAt = Date.now()
            if (typeof options.complete === 'function') {
              options.complete(res)
            }
          }
        }
        try {
          const result = original.call(this, nextOptions)
          marker.queued = true
          return result
        } catch (err) {
          marker.error = err && err.message ? err.message : String(err)
          marker.finishedAt = Date.now()
          throw err
        }
      }
      globalThis.__virtualPaymentObserverInstalled = true
      return { ok: true, wrapped: true }
    } catch (err) {
      return {
        ok: false,
        reason: err && err.message ? err.message : String(err)
      }
    }
  })
}

async function installCashierFlowObserver(miniProgram) {
  return miniProgram.evaluate(() => {
    const pages = getCurrentPages()
    const page = pages.length ? pages[pages.length - 1] : null
    const vm = page && page.$vm
    if (!vm) {
      return { ok: false, reason: 'cashier vm missing' }
    }
    if (globalThis.__cashierFlowObserverInstalled) {
      return { ok: true, reused: true }
    }
    const simplifyPayment = payment => payment ? {
      provider: payment.provider || '',
      platform: payment.platform || '',
      outTradeNo: payment.outTradeNo || payment.out_trade_no || '',
      mode: payment.mode || '',
      hasSignData: Boolean(payment.signData),
      hasPaySig: Boolean(payment.paySig),
      hasSignature: Boolean(payment.signature)
    } : null
    const simplifyResult = result => result ? {
      status: result.status,
      message: result.message || '',
      payment: simplifyPayment(result.data && result.data.payment ? result.data.payment : null)
    } : null
    const simplifyError = err => err ? {
      errMsg: err.errMsg || '',
      message: err.message || '',
      errCode: err.errCode ?? null
    } : null

    const originalSubmitCallback = typeof vm.onSubmitCallback === 'function' ? vm.onSubmitCallback.bind(vm) : null
    const originalPayFail = typeof vm.onPayFail === 'function' ? vm.onPayFail.bind(vm) : null
    const originalPaySuccess = typeof vm.onPaySuccess === 'function' ? vm.onPaySuccess.bind(vm) : null

    if (!originalSubmitCallback || !originalPayFail || !originalPaySuccess) {
      return { ok: false, reason: 'cashier hooks missing' }
    }

    vm.onSubmitCallback = function wrappedOnSubmitCallback(result) {
      globalThis.__cashierFlowObserverState = {
        ...(globalThis.__cashierFlowObserverState || {}),
        submitCallbackAt: Date.now(),
        submitResult: simplifyResult(result)
      }
      return originalSubmitCallback(result)
    }

    vm.onPayFail = function wrappedOnPayFail(err) {
      globalThis.__cashierFlowObserverState = {
        ...(globalThis.__cashierFlowObserverState || {}),
        payFailAt: Date.now(),
        payFail: simplifyError(err)
      }
      return originalPayFail(err)
    }

    vm.onPaySuccess = function wrappedOnPaySuccess(payload) {
      globalThis.__cashierFlowObserverState = {
        ...(globalThis.__cashierFlowObserverState || {}),
        paySuccessAt: Date.now(),
        paySuccess: payload ? {
          option: payload.option ? {
            isRequireQuery: Boolean(payload.option.isRequireQuery),
            outTradeNo: payload.option.outTradeNo || '',
            method: payload.option.method || ''
          } : null
        } : null
      }
      return originalPaySuccess(payload)
    }

    globalThis.__cashierFlowObserverInstalled = true
    return { ok: true, wrapped: true }
  })
}

async function getCashierFlowObserverState(miniProgram) {
  return miniProgram.evaluate(() => {
    return globalThis.__cashierFlowObserverState || null
  })
}

async function getVirtualPaymentObserverState(miniProgram) {
  return miniProgram.evaluate(() => {
    const pages = getCurrentPages()
    const page = pages.length ? pages[pages.length - 1] : null
    const tempUnifyData = page && page.$vm && page.$vm.orderId
      ? wx.getStorageSync(`tempUnifyData_${page.$vm.orderId}`)
      : null
    return {
      route: page && (page.route || page.__route__),
      marker: globalThis.__virtualPaymentNativeCall || null,
      tempUnifyData
    }
  })
}

async function waitForVirtualPaymentObserver(miniProgram, timeoutMs = 20000, intervalMs = 1000) {
  const startedAt = Date.now()
  let latest = null
  while ((Date.now() - startedAt) < timeoutMs) {
    const [virtualState, cashierFlowState] = await Promise.all([
      getVirtualPaymentObserverState(miniProgram),
      getCashierFlowObserverState(miniProgram)
    ])
    latest = {
      ...virtualState,
      cashierFlowState
    }
    const marker = latest && latest.marker
    const tempUnifyData = latest && latest.tempUnifyData
    const latestCashierFlowState = latest && latest.cashierFlowState
    if ((marker && (marker.signData || marker.queued || marker.error || marker.fail || marker.success))
      || (tempUnifyData && tempUnifyData.outTradeNo)
      || (latestCashierFlowState && (latestCashierFlowState.submitResult || latestCashierFlowState.payFail || latestCashierFlowState.paySuccess))) {
      return latest
    }
    await sleep(intervalMs)
  }
  return latest
}

async function runNativePayment(miniProgram, payment, evidence) {
  let allowBridgeClose = false
  const bridgeClosePattern = /Connection closed, check if wechat web devTools is still running/i
  const suppressBridgeClose = err => {
    const message = err && err.stack ? err.stack : String(err)
    if (allowBridgeClose && bridgeClosePattern.test(message)) {
      evidence.bridgeCloseNotice = message
      writeEvidence(evidence)
      return true
    }
    return false
  }

  const uncaughtExceptionHandler = err => {
    if (!suppressBridgeClose(err)) {
      process.stderr.write((err && err.stack ? err.stack : String(err)) + '\n')
    }
  }
  const unhandledRejectionHandler = reason => {
    if (!suppressBridgeClose(reason)) {
      const err = reason instanceof Error ? reason : new Error(String(reason))
      process.stderr.write((err.stack || String(err)) + '\n')
    }
  }

  process.on('uncaughtException', uncaughtExceptionHandler)
  process.on('unhandledRejection', unhandledRejectionHandler)

  try {
    allowBridgeClose = true
    return await withTimeout(miniProgram.evaluate(nextPayment => {
      const mode = nextPayment.mode || 'short_series_goods'
      const marker = {
        triggeredAt: Date.now(),
        mode,
        outTradeNo: nextPayment.outTradeNo || '',
        queued: false
      }
      try {
        wx.requestVirtualPayment({
          signData: nextPayment.signData,
          paySig: nextPayment.paySig,
          signature: nextPayment.signature,
          mode,
          success(res) {
            marker.success = res
            marker.finishedAt = Date.now()
          },
          fail(err) {
            marker.fail = err
            marker.finishedAt = Date.now()
          },
          complete(res) {
            marker.complete = res
            marker.finishedAt = Date.now()
          }
        })
        marker.queued = true
      } catch (err) {
        marker.error = err && err.message ? err.message : String(err)
      }
      globalThis.__virtualPaymentNativeCall = marker
      return marker
    }, {
      signData: payment.signData,
      paySig: payment.paySig,
      signature: payment.signature,
      mode: payment.mode || 'short_series_goods',
      outTradeNo: payment.outTradeNo || payment.out_trade_no || ''
    }), 10000, 'wx.requestVirtualPayment enqueue')
  } catch (err) {
    if (!suppressBridgeClose(err)) {
      const message = err && err.message ? err.message : String(err)
      if (/wx\.requestVirtualPayment enqueue timeout/i.test(message)) {
        evidence.enqueueTimeout = message
        writeEvidence(evidence)
        return {
          queued: null,
          timeout: true,
          error: message
        }
      }
      throw err
    }
    return null
  } finally {
    process.off('uncaughtException', uncaughtExceptionHandler)
    process.off('unhandledRejection', unhandledRejectionHandler)
  }
}

async function triggerRealCashierPayment(miniProgram, token, userId, orderId, evidence) {
  await miniProgram.reLaunch('/pages/user/index')
  await sleep(2000)
  evidence.realLoginState = await injectLoginState(miniProgram, token, userId)
  writeEvidence(evidence)

  const page = await miniProgram.reLaunch(`/pages/checkout/cashier/index?orderId=${orderId}`)
  await page.waitFor(5000)
  await sleep(2000)
  const beforeSubmit = await waitForCashierReady(miniProgram, 30000, 1000)
  const cashierFlowObserverInstall = await installCashierFlowObserver(miniProgram)
  const observerInstall = await installVirtualPaymentObserver(miniProgram)
  const submitResult = await triggerCashierSubmit(miniProgram)
  const observerState = await waitForVirtualPaymentObserver(miniProgram, 20000, 1000)
  return {
    beforeSubmit,
    cashierFlowObserverInstall,
    observerInstall,
    submitResult,
    observerState
  }
}

async function pollPaymentState(orderId, outTradeNo, token, evidence) {
  evidence.poll = []
  for (let index = 0; index < pollLimit; index += 1) {
    await sleep(pollMs)
    const query = await tradeQuery(outTradeNo, token)
    const detail = await orderDetail(orderId, token)
    const snapshot = { index: index + 1, query, detail }
    evidence.poll.push(snapshot)
    evidence.stage = `poll-${index + 1}`
    writeEvidence(evidence)
    const isPaidByQuery = Boolean(query.data?.isPay || query.data?.data?.isPay)
    const payStatus = Number(detail.data?.data?.order?.pay_status || 0)
    if (isPaidByQuery || payStatus === 20) {
      evidence.final = snapshot
      evidence.completedAt = new Date().toISOString()
      return snapshot
    }
  }
  return null
}

async function main() {
  const evidence = {
    startedAt: new Date().toISOString(),
    apiBase,
    expectedApiUrl,
    workspaceApiUrl,
    orderPlatform,
    debugMobile,
    goodsId,
    goodsSkuId,
    wsPort,
    windowsCmd,
    wechatCliWin,
    projectPathWin,
    shouldReopenManualDevtools,
    runId,
    evidencePath,
    shouldHardResetDevtools
  }
  writeEvidence(evidence)

  ensureMiniProgramBuild()
  evidence.apiAlignment = ensureApiAlignment()
  evidence.stage = 'apiAlignmentReady'
  writeEvidence(evidence)
  evidence.devtoolsReset = ensureDevtoolsCleanSlate()
  evidence.stage = 'devtoolsReset'
  writeEvidence(evidence)
  ensureDevtoolsAutomation()
  evidence.stage = 'devtoolsAutomationReady'
  writeEvidence(evidence)

  const identity = createLocalDebugIdentity()
  evidence.identity = identity
  evidence.stage = 'identityReady'
  writeEvidence(evidence)

  let miniProgram = null
  try {
    miniProgram = await connectMiniProgram()
    evidence.captureSkipped = true
    evidence.stage = 'captureSkipped'
    writeEvidence(evidence)

    const realOrderId = await createServiceOrder(identity.token, 'REAL')
    const realCashier = await getOrderInfo(realOrderId, identity.token)
    evidence.realOrderId = realOrderId
    evidence.realCashier = {
      orderId: realOrderId,
      payStatus: realCashier.order?.pay_status,
      methods: (realCashier.paymentMethods || []).map(item => item.method)
    }
    evidence.realFrontSubmit = await triggerRealCashierPayment(miniProgram, identity.token, identity.userId, realOrderId, evidence)
    evidence.realPayment = sanitizePayment({
      provider: 'virtual',
      platform: 'wechat_virtual',
      outTradeNo: evidence.realFrontSubmit?.observerState?.tempUnifyData?.outTradeNo || '',
      mode: evidence.realFrontSubmit?.observerState?.marker?.mode || 'short_series_goods',
      signData: evidence.realFrontSubmit?.observerState?.marker?.signData || '',
      paySig: evidence.realFrontSubmit?.observerState?.marker?.paySig || '',
      signature: evidence.realFrontSubmit?.observerState?.marker?.signature || ''
    })
    evidence.stage = 'realPaymentTriggeredFromCashier'
    writeEvidence(evidence)

    if (!evidence.realPayment || !evidence.realPayment.outTradeNo) {
      throw new Error(`real payment missing outTradeNo: ${JSON.stringify(evidence.realFrontSubmit || {})}`)
    }

    await pollPaymentState(realOrderId, evidence.realPayment.outTradeNo, identity.token, evidence)
    evidence.stage = 'finished'
    writeEvidence(evidence)
    console.log(JSON.stringify(evidence, null, 2))
  } finally {
    if (miniProgram) {
      try {
        await miniProgram.close()
      } catch (err) {
        try {
          miniProgram.disconnect()
        } catch (disconnectErr) {
          // ignore
        }
        evidence.automationCloseError = err && err.stack ? err.stack : String(err)
      }
    }
    evidence.devtoolsManualReset = restoreManualDevtoolsSession()
    evidence.stage = 'devtoolsManualReset'
    writeEvidence(evidence)
  }
}

main().catch(err => {
  let previous = {}
  try {
    previous = JSON.parse(fs.readFileSync(evidencePath, 'utf8'))
  } catch (readErr) {
    previous = {}
  }
  writeEvidence({
    ...previous,
    failedAt: new Date().toISOString(),
    error: err && err.stack ? err.stack : String(err),
    evidencePath
  })
  console.error(err && err.stack ? err.stack : String(err))
  process.exit(1)
})
