const assert = require('assert')
const fs = require('fs')
const os = require('os')
const path = require('path')
const {
  API_URLS,
  assertSafeMirrorRoot,
  parseEnvironment,
  verifyCompiledDomain,
  withTemporaryApiUrlOverride,
} = require('../build-mp-weixin.cjs')

async function testAsyncRestoration() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'yoshop-mp-config-'))
  const config = path.join(root, 'config.js')
  const original = 'export default { apiUrl: "https://wx.oiob.cn/index.php?s=/api/" }\n'
  fs.writeFileSync(config, original)
  let release
  const gate = new Promise(resolve => { release = resolve })
  let callbackStarted = false
  const pending = withTemporaryApiUrlOverride([config], API_URLS.production, async () => {
    callbackStarted = true
    assert.match(fs.readFileSync(config, 'utf8'), /wx\.gxwqb\.cn/)
    await gate
    assert.match(fs.readFileSync(config, 'utf8'), /wx\.gxwqb\.cn/)
  })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(callbackStarted, true)
  assert.match(fs.readFileSync(config, 'utf8'), /wx\.gxwqb\.cn/)
  release()
  await pending
  assert.equal(fs.readFileSync(config, 'utf8'), original)
  fs.rmSync(root, { recursive: true, force: true })
}

function testEnvironmentParser() {
  assert.equal(parseEnvironment(['--environment', 'test']), 'test')
  assert.equal(parseEnvironment(['--environment', 'production']), 'production')
  assert.throws(() => parseEnvironment(['--environment', 'staging']), /must be one of/)
}

function testMirrorGuard() {
  const source = fs.mkdtempSync(path.join(os.tmpdir(), 'yoshop-source-'))
  const unsafe = fs.mkdtempSync(path.join(os.tmpdir(), 'yoshop-mirror-'))
  fs.writeFileSync(path.join(unsafe, '.yoshop-windows-mirror.json'), JSON.stringify({
    kind: 'yoshop-windows-mirror', projectName: 'yoshop2.0-uniapp',
  }))
  assert.throws(() => assertSafeMirrorRoot(source, unsafe, 'yoshop2.0-uniapp'), /below \/mnt/)
  fs.rmSync(source, { recursive: true, force: true })
  fs.rmSync(unsafe, { recursive: true, force: true })
}

function testDomainVerification() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'yoshop-mp-output-'))
  fs.writeFileSync(path.join(root, 'config.js'), `const url='${API_URLS.production}'`)
  verifyCompiledDomain(root, API_URLS.production)
  assert.throws(() => verifyCompiledDomain(root, API_URLS.test), /does not contain/)
  fs.writeFileSync(path.join(root, 'config.js'), `${API_URLS.production} ${API_URLS.test}`)
  assert.throws(() => verifyCompiledDomain(root, API_URLS.production), /wrong environment/)
  fs.rmSync(root, { recursive: true, force: true })
}

async function main() {
  testEnvironmentParser()
  testMirrorGuard()
  testDomainVerification()
  await testAsyncRestoration()
  console.log('PASS build-mp-weixin guard tests')
}

main().catch(err => { console.error(err); process.exit(1) })
