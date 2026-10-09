import { reactive } from 'vue'

/**
 * Imágenes de los botones del teclado táctil. DefPlus.H_ICON guarda la ruta del fichero
 * en el disco del PC de caja (como legacy), así que solo Electron puede leerlas; en el
 * navegador los botones salen sin imagen.
 */
const imagenes = reactive(new Map<string, string>())
const pendientes = new Set<string>()

export function puedeUsarImagenesTeclado(): boolean {
  return typeof window !== 'undefined' && typeof window.descartes?.imagenTeclado === 'function'
}

/** Data URL ya cargada, o '' mientras se lee (al terminar el Map reactivo repinta). */
export function imagenTeclado(ruta: string | null | undefined): string {
  const r = String(ruta ?? '').trim()
  if (!r || !puedeUsarImagenesTeclado()) return ''
  const cache = imagenes.get(r)
  if (cache !== undefined) return cache
  if (!pendientes.has(r)) {
    pendientes.add(r)
    void window.descartes!.imagenTeclado!(r)
      .then((res) => imagenes.set(r, res.ok ? res.dataUrl || '' : ''))
      .catch(() => imagenes.set(r, ''))
      .finally(() => pendientes.delete(r))
  }
  return ''
}

export function recordarImagenTeclado(ruta: string, dataUrl: string) {
  if (ruta) imagenes.set(ruta.trim(), dataUrl)
}
