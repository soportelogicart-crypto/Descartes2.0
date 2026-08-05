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

export function validarInteresComercial(fila: InteresComercialFila): string | null {
  const codigo = String(fila.codigo ?? '').trim()
  if (!codigo) return 'El codigo es obligatorio'
  if (codigo.length > 2) return 'El codigo admite como maximo 2 caracteres'
  if (!String(fila.descripcion ?? '').trim()) return 'La descripcion es obligatoria'
  if (String(fila.descripcion ?? '').trim().length > 50) {
    return 'La descripcion admite como maximo 50 caracteres'
  }
  return null
}

export function payloadInteresComercial(fila: InteresComercialFila): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
  }
}
