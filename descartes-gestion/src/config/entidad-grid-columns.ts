import type { CampoEntidad } from '@/config/entidades'

export type GridColumnType = 'text' | 'number' | 'checkbox' | 'select'

export type GridColumn = {
  key: string
  label: string
  type: GridColumnType
  width?: string
  maxLength?: number
  readOnly?: boolean
  optionsSource?: CampoEntidad['optionsSource']
  required?: boolean
}

export type GridFila = Record<string, unknown> & {
  codigo?: string | number | null
  _nuevo?: boolean
  _dirty?: boolean
}

/** Columnas visibles en la rejilla (alta via formulario/ficha). */
export const entidadGridColumns: Record<string, GridColumn[]> = {
  impuestos: [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '5rem', maxLength: 2, required: true },
    { key: 'descripcion', label: 'Descripcion', type: 'text', width: '14rem', maxLength: 40, required: true },
    { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
  ],
  'formas-pago': [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '4.5rem', maxLength: 2, required: true },
    { key: 'descripcion', label: 'Descripcion', type: 'text', width: '12rem', maxLength: 40, required: true },
    { key: 'abreviacion', label: 'Abr.', type: 'text', width: '3.5rem', maxLength: 3 },
    { key: 'cobroPago', label: 'C/P', type: 'text', width: '3rem', maxLength: 1 },
    { key: 'cobroDeArqueo', label: 'Arqueo', type: 'checkbox', width: '4.5rem' },
    { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
  ],
  roles: [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '5rem', maxLength: 20, required: true },
    { key: 'nombre', label: 'Nombre', type: 'text', width: '14rem', maxLength: 50, required: true },
    { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
  ],
  usuarios: [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '6rem', maxLength: 20, required: true },
    { key: 'nombre', label: 'Nombre', type: 'text', width: '12rem', maxLength: 50, required: true },
    { key: 'rolCodigo', label: 'Rol', type: 'select', width: '8rem', optionsSource: 'roles', required: true },
    { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
  ],
  trabajadores: [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '5rem', maxLength: 4, required: true },
    { key: 'nombre', label: 'Nombre', type: 'text', width: '12rem', maxLength: 50, required: true },
    { key: 'comision', label: 'Comision', type: 'number', width: '5.5rem' },
    { key: 'agente', label: 'Agente', type: 'checkbox', width: '4rem' },
    { key: 'vendedor', label: 'Vendedor', type: 'checkbox', width: '4.5rem' },
    { key: 'operario', label: 'Operario', type: 'checkbox', width: '4.5rem' },
    { key: 'tecnico', label: 'Tecnico', type: 'checkbox', width: '4rem' },
    { key: 'usuarioCodigo', label: 'Usuario', type: 'select', width: '8rem', optionsSource: 'usuarios' },
    { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
  ],
  clientes: [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '5rem', maxLength: 15, required: true },
    { key: 'tiendaCodigo', label: 'Tienda', type: 'select', width: '7rem', optionsSource: 'tiendas' },
    { key: 'nombre', label: 'Razon social', type: 'text', width: '12rem', maxLength: 80, required: true },
    { key: 'nif', label: 'NIF', type: 'text', width: '6rem', maxLength: 20 },
    { key: 'telefono1', label: 'Telefono', type: 'text', width: '6rem', maxLength: 20 },
    { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
  ],
  proveedores: [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '5rem', maxLength: 6, required: true },
    { key: 'nombre', label: 'Razon social', type: 'text', width: '16rem', maxLength: 50, required: true },
    { key: 'nif', label: 'NIF', type: 'text', width: '7rem', maxLength: 16 },
  ],
  tiendas: [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '4rem', maxLength: 3, required: true },
    { key: 'nombre', label: 'Nombre', type: 'text', width: '12rem', maxLength: 50, required: true },
    { key: 'nif', label: 'NIF', type: 'text', width: '6rem', maxLength: 20 },
    { key: 'poblacion', label: 'Poblacion', type: 'text', width: '8rem', maxLength: 40 },
    { key: 'telefono1', label: 'Telefono', type: 'text', width: '6rem', maxLength: 20 },
    { key: 'activo', label: 'Activo', type: 'checkbox', width: '4rem' },
  ],
  'puestos-trabajo': [
    { key: 'codigo', label: 'Codigo', type: 'text', width: '4rem', maxLength: 3, required: true },
    { key: 'descripcion', label: 'Descripcion', type: 'text', width: '14rem', maxLength: 50, required: true },
    {
      key: 'tiendaCodigo',
      label: 'Tienda arqueo',
      type: 'select',
      width: '8rem',
      optionsSource: 'tiendas',
    },
    {
      key: 'trabajadorCodigo',
      label: 'Vendedor',
      type: 'select',
      width: '8rem',
      optionsSource: 'trabajadores',
    },
  ],
  'oferta-proveedores': [
    { key: 'articulo', label: 'Articulo', type: 'text', width: '8rem', readOnly: true },
    { key: 'proveedor', label: 'Proveedor', type: 'text', width: '6rem', readOnly: true },
    { key: 'fechaInicio', label: 'Fecha inicio', type: 'text', width: '7rem', readOnly: true },
    { key: 'fechaFin', label: 'Fecha fin', type: 'text', width: '7rem', readOnly: true },
  ],
}

export function getGridColumns(entidad: string): GridColumn[] {
  return entidadGridColumns[entidad] ?? []
}

/** Rejillas compactas (2–3 columnas): toolbar y grid al 50% de ancho. */
export function esGridEstrecho(columns: readonly unknown[]): boolean {
  return columns.length >= 2 && columns.length <= 3
}

export function filaVaciaDesdeColumnas(columns: GridColumn[]): GridFila {
  const fila: GridFila = { _nuevo: true, _dirty: true, activo: true }
  for (const col of columns) {
    if (col.key === 'activo') fila.activo = true
    else if (col.type === 'checkbox') fila[col.key] = false
    else if (col.type === 'number') fila[col.key] = 0
    else fila[col.key] = ''
  }
  return fila
}

export function clonarFilaGrid(item: Record<string, unknown>, columns: GridColumn[]): GridFila {
  const fila: GridFila = {
    codigo: item.codigo ?? '',
    activo: item.activo !== false && item.activo !== 0,
    _nuevo: false,
    _dirty: false,
  }
  for (const col of columns) {
    if (col.key === 'codigo') {
      fila.codigo = item.codigo == null ? '' : String(item.codigo).trim()
      continue
    }
    if (col.type === 'checkbox') {
      fila[col.key] = item[col.key] === true || item[col.key] === 1
      continue
    }
    if (col.type === 'number') {
      fila[col.key] = item[col.key] == null || item[col.key] === '' ? 0 : Number(item[col.key])
      continue
    }
    fila[col.key] = item[col.key] == null ? '' : String(item[col.key]).trim()
  }
  return fila
}

export function validarFilaGrid(fila: GridFila, columns: GridColumn[]): string | null {
  for (const col of columns) {
    if (!col.required) continue
    const value = fila[col.key]
    if (col.type === 'number') {
      if (value == null || value === '' || Number.isNaN(Number(value))) {
        return `${col.label} es obligatorio`
      }
      continue
    }
    if (col.type === 'checkbox') continue
    if (!String(value ?? '').trim()) return `${col.label} es obligatorio`
  }
  return null
}

export function payloadFilaGrid(fila: GridFila, columns: GridColumn[]): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    codigo: String(fila.codigo ?? '').trim(),
    activo: esFilaActiva(fila),
  }
  for (const col of columns) {
    if (col.key === 'codigo') continue
    if (col.key === 'activo') {
      payload.activo = esFilaActiva(fila)
      continue
    }
    if (col.type === 'checkbox') {
      payload[col.key] = Boolean(fila[col.key])
      continue
    }
    if (col.type === 'number') {
      payload[col.key] = fila[col.key] == null || fila[col.key] === '' ? 0 : Number(fila[col.key])
      continue
    }
    const text = String(fila[col.key] ?? '').trim()
    payload[col.key] = text || null
  }
  return payload
}

/** Activo en rejilla: true solo si el valor es claramente activo. */
export function esFilaActiva(fila: Record<string, unknown>): boolean {
  const v = fila.activo
  return v === true || v === 1 || v === '1'
}
