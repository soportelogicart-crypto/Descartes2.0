/** Etiquetas UI alineadas con `Vales.Tipo` legacy. */
export const VALES_TIPO_LABEL: Record<string, string> = {
  I: 'Entrada efectivo',
  R: 'Vale regalo',
  P: 'Pagos',
  S: 'Salida efectivo',
  V: 'Vale de compra',
  M: 'Vale fidelización',
}

export function etiquetaTipoVale(tipo: string | null | undefined, tipoLabel?: string | null) {
  if (tipoLabel) return tipoLabel
  const t = String(tipo ?? '').trim().toUpperCase()
  if (!t) return '—'
  return VALES_TIPO_LABEL[t] ?? t
}

export const VALES_TIPO_FILTRO = [
  { value: '', label: 'Todos (R/V/M)' },
  { value: 'R', label: 'Vale regalo' },
  { value: 'V', label: 'Vale de compra' },
  { value: 'M', label: 'Fidelización' },
  { value: 'I', label: 'Entrada efectivo' },
  { value: 'S', label: 'Salida efectivo' },
  { value: 'P', label: 'Pagos' },
] as const
