export type AlmacenColumnType = 'number' | 'text' | 'checkbox'

export type AlmacenColumn = {
  key: string
  label: string
  type: AlmacenColumnType
  width?: string
}

export const almacenColumns: AlmacenColumn[] = [
  { key: 'codigo', label: 'Codigo', type: 'number', width: '4.5rem' },
  { key: 'descripcion', label: 'Descripcion', type: 'text', width: '12rem' },
  { key: 'externo', label: 'Externo', type: 'checkbox', width: '4rem' },
  { key: 'reservaDirecta', label: 'Reserva', type: 'checkbox', width: '4.5rem' },
  { key: 'central', label: 'Central', type: 'checkbox', width: '4rem' },
  { key: 'traspasoAutomatico', label: 'Auto', type: 'checkbox', width: '3.5rem' },
  { key: 'consolidaStockWeb', label: 'Stock Web', type: 'checkbox', width: '5rem' },
  { key: 'centroCoste', label: 'Centro de Coste', type: 'text', width: '7rem' },
  { key: 'gastos', label: 'Gastos', type: 'checkbox', width: '4rem' },
  { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
]

export type AlmacenFila = Record<string, unknown> & {
  codigo?: number | null
  descripcion?: string
  externo?: boolean
  reservaDirecta?: boolean
  central?: boolean
  traspasoAutomatico?: boolean
  consolidaStockWeb?: boolean
  centroCoste?: string
  gastos?: boolean
  activo?: boolean
  _nuevo?: boolean
  _dirty?: boolean
}

export function almacenVacio(): AlmacenFila {
  return {
    codigo: null,
    descripcion: '',
    externo: false,
    reservaDirecta: false,
    central: false,
    traspasoAutomatico: false,
    consolidaStockWeb: false,
    centroCoste: '',
    gastos: false,
    activo: true,
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarFila(item: Record<string, unknown>): AlmacenFila {
  return {
    codigo: item.codigo != null ? Number(item.codigo) : null,
    descripcion: String(item.descripcion ?? ''),
    externo: Boolean(item.externo),
    reservaDirecta: Boolean(item.reservaDirecta),
    central: Boolean(item.central),
    traspasoAutomatico: Boolean(item.traspasoAutomatico),
    consolidaStockWeb: Boolean(item.consolidaStockWeb),
    centroCoste: String(item.centroCoste ?? ''),
    gastos: Boolean(item.gastos),
    activo: item.activo !== false,
    _nuevo: false,
    _dirty: false,
  }
}

export function validarAlmacen(fila: AlmacenFila): string | null {
  if (fila.codigo == null || fila.codigo === '' || Number(fila.codigo) <= 0) {
    return 'El codigo es obligatorio'
  }
  if (!String(fila.descripcion ?? '').trim()) {
    return 'La descripcion es obligatoria'
  }
  return null
}

export function payloadAlmacen(fila: AlmacenFila): Record<string, unknown> {
  return {
    codigo: Number(fila.codigo),
    descripcion: String(fila.descripcion ?? '').trim(),
    externo: Boolean(fila.externo),
    reservaDirecta: Boolean(fila.reservaDirecta),
    central: Boolean(fila.central),
    traspasoAutomatico: Boolean(fila.traspasoAutomatico),
    consolidaStockWeb: Boolean(fila.consolidaStockWeb),
    centroCoste: String(fila.centroCoste ?? '').trim(),
    gastos: Boolean(fila.gastos),
    activo: fila.activo !== false,
  }
}
