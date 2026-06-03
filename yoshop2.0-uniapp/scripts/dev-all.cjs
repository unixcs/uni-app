const { spawnSync } = require('child_process')

const sourceRoot = '/opt/yoshop/yoshop2.0-uniapp'
const mirrorRoot = '/mnt/d/Program/0/home/0/yoshop1/yoshop2.0-uniapp'

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

function syncSourceToMirror() {
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
}

console.log('[dev:all] Syncing WSL source to Windows mirror...')
syncSourceToMirror()

console.log('[dev:all] Building H5 and syncing to public...')
run('npm', ['run', 'build:h5:sync'], sourceRoot)

console.log('[dev:all] Building mp-weixin in Windows mirror...')
run('npm', ['run', 'build:mp-weixin'], mirrorRoot)

console.log('[dev:all] Done.')
