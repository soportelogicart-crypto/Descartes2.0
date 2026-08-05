export type ArticuloColumnType = 'text' | 'number' | 'select' | 'checkbox'

export type ArticuloColumn = {
  key: string
  label: string
  type: ArticuloColumnType
  width?: string
  readOnly?: boolean
  maxLength?: number
  optionsSource?: 'familias' | 'impuestos' | 'proveedores'
}

export const articuloColumns: ArticuloColumn[] = [
  { key: 'codigo', label: 'Codigo', type: 'text', width: '7rem', maxLength: 18 },
  { key: 'descripcion', label: 'Descripcion', type: 'text', width: '14rem', maxLength: 50 },
  { key: 'familia', label: 'Familia', type: 'select', width: '8rem', optionsSource: 'familias' },
  { key: 'impuestoCodigo', label: 'Impuesto', type: 'select', width: '7rem', optionsSource: 'impuestos' },
  { key: 'proveedorHabitual', label: 'Proveedor', type: 'select', width: '8rem', optionsSource: 'proveedores' },
  { key: 'precioVen1', label: 'PVP', type: 'number', width: '5.5rem' },
  { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
]

export type ArticuloFila = {
  codigo?: string
  descripcion?: string
  familia?: string
  impuestoCodigo?: string
  proveedorHabitual?: string
  precioVen1?: number | null
  precioVenta?: number | null
  activo?: boolean
  _nuevo?: boolean
  _dirty?: boolean
}

export function articuloFilaVacia(): ArticuloFila {
  return {
    codigo: '',
    descripcion: '',
    familia: '',
    impuestoCodigo: '',
    proveedorHabitual: '',
    precioVen1: 0,
    precioVenta: 0,
    activo: true,
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarArticuloFila(item: Record<string, unknown>): ArticuloFila {
  const pvp =
    item.precioVen1 != null && item.precioVen1 !== ''
      ? Number(item.precioVen1)
      : item.precioVenta == null || item.precioVenta === ''
        ? 0
        : Number(item.precioVenta)

  return {
    codigo: String(item.codigo ?? '').trim(),
    descripcion: String(item.descripcion ?? ''),
    familia: String(item.familia ?? '').trim(),
    impuestoCodigo: String(item.impuestoCodigo ?? '').trim(),
    proveedorHabitual: String(item.proveedorHabitual ?? '').trim(),
    precioVen1: pvp,
    precioVenta: pvp,
    activo: item.activo !== false && item.activo !== 0,
    _nuevo: false,
    _dirty: false,
  }
}

export function validarArticuloFila(fila: ArticuloFila): string | null {
  const codigo = String(fila.codigo ?? '').trim()
  if (!codigo) return 'El codigo es obligatorio'
  if (codigo.length > 18) return 'El codigo admite como maximo 18 caracteres'
  if (!String(fila.descripcion ?? '').trim()) return 'La descripcion es obligatoria'
  if (!String(fila.impuestoCodigo ?? '').trim()) return 'El impuesto es obligatorio'
  return null
}

export function payloadArticuloFila(fila: ArticuloFila): Record<string, unknown> {
  const pvp = fila.precioVen1 == null ? 0 : Number(fila.precioVen1)
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
    familia: String(fila.familia ?? '').trim() || null,
    impuestoCodigo: String(fila.impuestoCodigo ?? '').trim(),
    proveedorHabitual: String(fila.proveedorHabitual ?? '').trim() || null,
    precioVen1: pvp,
    precioVenta: pvp,
    activo: fila.activo !== false,
  }
}
