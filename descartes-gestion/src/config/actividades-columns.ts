export type ActividadFila = {
  codigo?: string
  descripcion?: string
  _nuevo?: boolean
  _dirty?: boolean
}

export function actividadVacia(): ActividadFila {
  return {
    codigo: '',
    descripcion: '',
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarActividad(item: Record<string, unknown>): ActividadFila {
  return {
    codigo: String(item.codigo ?? '').trim(),
    descripcion: String(item.descripcion ?? ''),
    _nuevo: false,
    _dirty: false,
  }
}

export function payloadActividad(fila: Record<string, unknown>): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
  }
}

export const ACTIVIDAD_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
]

export function camposActividadObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  return vacios
}

export function validarActividadObligatorios(ficha: Record<string, unknown>): string | null {
  const key = camposActividadObligatoriosVacios(ficha)[0]
  if (!key) return null
  const codigo = String(ficha.codigo ?? '').trim()
  if (key === 'codigo' && codigo.length > 6) {
    return 'El codigo admite como maximo 6 caracteres'
  }
  const label = ACTIVIDAD_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
  return `El campo "${label}" es obligatorio.`
}
