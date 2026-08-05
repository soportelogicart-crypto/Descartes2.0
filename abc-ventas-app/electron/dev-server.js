const { spawn } = require('child_process')
const path = require('path')
const http = require('http')

let viteProcess = null

function waitForUrl(url, { timeoutMs = 60000, intervalMs = 400 } = {}) {
  const started = Date.now()
  return new Promise((resolve, reject) => {
    const tick = () => {
      const req = http.get(url, (res) => {
        res.resume()
        resolve(true)
      })
      req.on('error', () => {
        if (Date.now() - started > timeoutMs) {
          reject(new Error(`Timeout esperando UI en ${url}`))
          return
        }
        setTimeout(tick, intervalMs)
      })
    }
    tick()
  })
}

function startViteDevServer() {
  if (viteProcess) {
    return Promise.resolve()
  }

  const frontendRoot = path.resolve(__dirname, '..', 'frontend')
  const npmCmd = process.platform === 'win32' ? 'npm.cmd' : 'npm'

  console.log(`[abc-ventas] Arrancando UI (Vite) en ${frontendRoot}`)

  viteProcess = spawn(npmCmd, ['run', 'dev', '--', '--host', '127.0.0.1', '--port', '5180', '--strictPort'], {
    cwd: frontendRoot,
    stdio: 'inherit',
    shell: process.platform === 'win32',
    env: { ...process.env, BROWSER: 'none' },
  })

  viteProcess.on('exit', (code) => {
    console.log(`[abc-ventas] Vite finalizó (code=${code})`)
    viteProcess = null
  })

  return waitForUrl('http://127.0.0.1:5180/')
}

function stopViteDevServer() {
  if (!viteProcess) return
  const child = viteProcess
  viteProcess = null
  try {
    if (process.platform === 'win32') {
      spawn('taskkill', ['/pid', String(child.pid), '/f', '/t'], { stdio: 'ignore' })
    } else {
      child.kill('SIGTERM')
    }
  } catch {
    // ignore
  }
}

module.exports = {
  startViteDevServer,
  stopViteDevServer,
  waitForUrl,
}
