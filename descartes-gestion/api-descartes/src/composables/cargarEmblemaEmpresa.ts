import { getDescartesBridge } from '@/bridge/electron'

const cache = new Map<string, Promise<string>>()

/**
 * Logo de la tienda para albarán/factura.
 * Solo hay imagen si existe un fichero en la carpeta `logos` (código de tienda).
 */
export function cargarEmblemaEmpresa(empresaCodigo: string): Promise<string> {
  const codigo = String(empresaCodigo ?? '').trim()
  if (!codigo) return Promise.resolve('')
  const hit = cache.get(codigo)
  if (hit) return hit
  const pending = resolver(codigo)
  cache.set(codigo, pending)
  return pending
}

export function invalidarEmblemaEmpresa(empresaCodigo: string) {
  const codigo = String(empresaCodigo ?? '').trim()
  if (codigo) cache.delete(codigo)
}

async function resolver(codigo: string): Promise<string> {
  const bridge = getDescartesBridge()
  if (bridge?.logoEmpresa) {
    try {
      const res = await bridge.logoEmpresa(codigo)
      if (res?.ok && typeof res.dataUrl === 'string' && res.dataUrl.startsWith('data:image/')) {
        return res.dataUrl
      }
    } catch {
      /* sin logo */
    }
  }
  return ''
}
