import { defineConfig, loadEnv, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, relative, resolve, sep } from 'node:path'
import { fileURLToPath, URL } from 'node:url'

const MANIFIESTO = 'ui-manifest.json'

function listarArchivos(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? listarArchivos(join(dir, e.name)) : [join(dir, e.name)]
  )
}

/**
 * `ui-manifest.json`: lista de archivos del build para que Electron guarde una
 * copia local de la gestión y pueda abrirla sin conexión. Se genera al final,
 * sobre la carpeta ya escrita, para incluir index.html y los archivos de public.
 */
function manifiestoUiLocal(): Plugin {
  let base = '/'
  let outDir = 'dist'
  return {
    name: 'descartes-ui-manifest',
    apply: 'build',
    configResolved(config) {
      base = config.base || '/'
      outDir = resolve(config.root, config.build.outDir)
    },
    closeBundle() {
      const archivos = listarArchivos(outDir)
        .map((f) => relative(outDir, f).split(sep).join('/'))
        .filter((n) => n !== MANIFIESTO && !n.endsWith('.php') && !n.endsWith('.map') && !n.endsWith('.htaccess'))
        .sort()
      const hash = createHash('sha1')
      for (const nombre of archivos) {
        hash.update(nombre)
        hash.update(readFileSync(join(outDir, nombre)))
      }
      writeFileSync(
        join(outDir, MANIFIESTO),
        JSON.stringify({ version: hash.digest('hex').slice(0, 16), generado: new Date().toISOString(), base, archivos }, null, 2)
      )
    },
  }
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE || '/',
    plugins: [vue(), manifiestoUiLocal()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: 5173,
      proxy: {
        '/api': {
          target: env.VITE_API_PROXY_TARGET || 'http://localhost',
          changeOrigin: true,
          secure: true,
          rewrite: (path) =>
            path.replace(/^\/api/, env.VITE_API_PROXY_PATH || '/descartes-api-dev/public/api'),
        },
      },
    },
  }
})
