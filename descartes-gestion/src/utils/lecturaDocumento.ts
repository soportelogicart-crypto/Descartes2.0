/**
 * Lector en modo teclado USA sobre un Windows en español: la tecla del guion
 * llega como apóstrofo (OFF'07'000002 en vez de OFF-07-000002).
 */
const APOSTROFO_DE_GUION = /['\u00B4`\u2018\u2019]/g

export function normalizarLecturaDocumento(texto: string): string {
  const limpio = String(texto ?? '').replace(APOSTROFO_DE_GUION, '-')
  const recortado = limpio.trim()
  if (/^OFF-/i.test(recortado)) return recortado.toUpperCase()
  return limpio
}

/** El código de barras del documento es empresa + albarán, o empresa + factura. */
export function coincideCodigoBarrasDocumento(
  texto: string,
  empresa: string,
  albaran: number,
  factura: number | null | undefined
): boolean {
  const digitos = texto.replace(/\D/g, '')
  const prefijo = empresa.trim()
  if (!prefijo || digitos.length <= prefijo.length || !digitos.startsWith(prefijo)) return false
  const resto = Number(digitos.slice(prefijo.length))
  if (!Number.isInteger(resto) || resto <= 0) return false
  return resto === albaran || (Number(factura) > 0 && resto === Number(factura))
}
