export type InteresComercialFila = {
  codigo?: string
  descripcion?: string
  _nuevo?: boolean
  _dirty?: boolean
}

export function interesComercialVacio(): InteresComercialFila {
  return {
    codigo: '',
    descripcion: '',
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarInteresComercial(item: Record<string, unknown>): InteresComercialFila {
  return {
    codigo: String(item.codigo ?? '').trim(),
    descripcion: String(item.descripcion ?? ''),
    _nuevo: false,
    _dirty: false,
  }
}

export function payloadInteresComercial(fila: Record<string, unknown>): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
  }
}

export const INTERES_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
]

export function camposInteresObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  return vacios
}

export function validarInteresObligatorios(ficha: Record<string, unknown>): string | null {
  const codigo = String(ficha.codigo ?? '').trim()
  if (!codigo) return 'El campo "Codigo" es obligatorio.'
  if (codigo.length > 2) return 'El codigo admite como maximo 2 caracteres'
  const desc = String(ficha.descripcion ?? '').trim()
  if (!desc) return 'El campo "Descripcion" es obligatorio.'
  if (desc.length > 50) return 'La descripcion admite como maximo 50 caracteres'
  return null
}
