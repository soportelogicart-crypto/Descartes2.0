export type MacrofamiliaFila = {
  codigo?: string
  descripcion?: string
  _nuevo?: boolean
  _dirty?: boolean
}

export function macrofamiliaVacia(): MacrofamiliaFila {
  return {
    codigo: '',
    descripcion: '',
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarMacrofamilia(item: Record<string, unknown>): MacrofamiliaFila {
  return {
    codigo: String(item.codigo ?? '').trim(),
    descripcion: String(item.descripcion ?? ''),
    _nuevo: false,
    _dirty: false,
  }
}

export function validarMacrofamilia(fila: MacrofamiliaFila): string | null {
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
  return null
}

export function payloadMacrofamilia(fila: Record<string, unknown>): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
  }
}

export const MACROFAMILIA_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
]

export function camposMacrofamiliaObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  return vacios
}

export function validarMacrofamiliaObligatorios(ficha: Record<string, unknown>): string | null {
  const key = camposMacrofamiliaObligatoriosVacios(ficha)[0]
  if (!key) return null
  const label = MACROFAMILIA_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
  return `El campo "${label}" es obligatorio.`
}
