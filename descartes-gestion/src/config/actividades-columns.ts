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

export function validarActividad(fila: ActividadFila): string | null {
  const codigo = String(fila.codigo ?? '').trim()
  if (!codigo) return 'El codigo es obligatorio'
  if (codigo.length > 6) return 'El codigo admite como maximo 6 caracteres'
  if (!String(fila.descripcion ?? '').trim()) return 'La descripcion es obligatoria'
  return null
}

export function payloadActividad(fila: ActividadFila): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
  }
}
