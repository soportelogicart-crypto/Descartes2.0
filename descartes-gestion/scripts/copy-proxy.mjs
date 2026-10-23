import { copyFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = dirname(fileURLToPath(import.meta.url))
copyFileSync(join(root, '../public/proxy.php'), join(root, '../dist/proxy.php'))
console.log('dist/proxy.php actualizado desde public/proxy.php')
