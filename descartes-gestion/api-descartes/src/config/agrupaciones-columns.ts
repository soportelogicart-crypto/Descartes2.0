export type AgrupacionFila = {
  codigo?: string
  descripcion?: string
  _nuevo?: boolean
  _dirty?: boolean
}

export function agrupacionVacia(): AgrupacionFila {
  return {
    codigo: '',
    descripcion: '',
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarAgrupacion(item: Record<string, unknown>): AgrupacionFila {
  return {
    codigo: String(item.codigo ?? '').trim(),
    descripcion: String(item.descripcion ?? ''),
    _nuevo: false,
    _dirty: false,
  }
}

export function validarAgrupacion(fila: AgrupacionFila): string | null {
  const codigo = String(fila.codigo ?? '').trim()
  if (!codigo) {
    return 'El codigo es obligatorio'
  }
  if (codigo.length > 6) {
    return 'El codigo admite como maximo 6 caracteres'
  }
  if (!String(fila.descripcion ?? '').trim()) {
    return 'La descripcion es obligatoria'
  }
  if (String(fila.descripcion ?? '').length > 50) {
    return 'La descripcion admite como maximo 50 caracteres'
  }
  return null
}

export function payloadAgrupacion(fila: Record<string, unknown>): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
  }
}

export const AGRUPACION_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
]

export function camposAgrupacionObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  return vacios
}

export function validarAgrupacionObligatorios(ficha: Record<string, unknown>): string | null {
  const key = camposAgrupacionObligatoriosVacios(ficha)[0]
  if (!key) return null
  const label = AGRUPACION_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
  return `El campo "${label}" es obligatorio.`
}
