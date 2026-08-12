export type SubfamiliaColumnType = 'text' | 'number' | 'select-familia'

export type SubfamiliaColumn = {
  key: string
  label: string
  type: SubfamiliaColumnType
  width?: string
  readOnly?: boolean
  maxLength?: number
}

export const subfamiliaColumns: SubfamiliaColumn[] = [
  { key: 'codigo', label: 'Codigo', type: 'text', width: '4.5rem', maxLength: 6 },
  { key: 'descripcion', label: 'Descripcion', type: 'text', width: '11rem', maxLength: 40 },
  { key: 'familiaCodigo', label: 'Familia', type: 'select-familia', width: '4.5rem' },
  { key: 'familiaNombre', label: 'Nombre', type: 'text', width: '11rem', readOnly: true },
  { key: 'cuentaCtb', label: 'Cuenta', type: 'number', width: '5.5rem' },
  { key: 'idWeb', label: 'idWeb', type: 'number', width: '4rem' },
  { key: 'idWeb2', label: 'idWeb2', type: 'number', width: '4rem' },
  { key: 'idWeb3', label: 'idWeb3', type: 'number', width: '4rem' },
  { key: 'idWeb4', label: 'idWeb4', type: 'number', width: '4rem' },
  { key: 'ctaTraspasoEntrada', label: 'CtaEntrada', type: 'number', width: '6rem' },
  { key: 'ctaTraspasoSalida', label: 'CtaSalidas', type: 'number', width: '6rem' },
]

export type SubfamiliaFila = {
  codigo?: string
  descripcion?: string
  familiaCodigo?: string
  familiaNombre?: string
  cuentaCtb?: number | null
  idWeb?: number | null
  idWeb2?: number | null
  idWeb3?: number | null
  idWeb4?: number | null
  ctaTraspasoEntrada?: number | null
  ctaTraspasoSalida?: number | null
  _nuevo?: boolean
  _dirty?: boolean
}

function numOrZero(value: unknown): number {
  return value == null || value === '' ? 0 : Number(value)
}

export function subfamiliaVacia(): SubfamiliaFila {
  return {
    codigo: '',
    descripcion: '',
    familiaCodigo: '',
    familiaNombre: '',
    cuentaCtb: 0,
    idWeb: 0,
    idWeb2: 0,
    idWeb3: 0,
    idWeb4: 0,
    ctaTraspasoEntrada: 0,
    ctaTraspasoSalida: 0,
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarSubfamilia(item: Record<string, unknown>): SubfamiliaFila {
  return {
    codigo: String(item.codigo ?? '').trim(),
    descripcion: String(item.descripcion ?? ''),
    familiaCodigo: String(item.familiaCodigo ?? '').trim(),
    familiaNombre: String(item.familiaNombre ?? ''),
    cuentaCtb: numOrZero(item.cuentaCtb),
    idWeb: numOrZero(item.idWeb),
    idWeb2: numOrZero(item.idWeb2),
    idWeb3: numOrZero(item.idWeb3),
    idWeb4: numOrZero(item.idWeb4),
    ctaTraspasoEntrada: numOrZero(item.ctaTraspasoEntrada),
    ctaTraspasoSalida: numOrZero(item.ctaTraspasoSalida),
    _nuevo: false,
    _dirty: false,
  }
}

export function validarSubfamilia(fila: SubfamiliaFila): string | null {
  const codigo = String(fila.codigo ?? '').trim()
  if (!codigo) return 'El codigo es obligatorio'
  if (codigo.length > 6) return 'El codigo admite como maximo 6 caracteres'
  if (!String(fila.descripcion ?? '').trim()) return 'La descripcion es obligatoria'
  if (!String(fila.familiaCodigo ?? '').trim()) return 'La familia es obligatoria'
  return null
}

export function payloadSubfamilia(fila: Record<string, unknown>): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
    familiaCodigo: String(fila.familiaCodigo ?? '').trim(),
    cuentaCtb: numOrZero(fila.cuentaCtb),
    idWeb: numOrZero(fila.idWeb),
    idWeb2: numOrZero(fila.idWeb2),
    idWeb3: numOrZero(fila.idWeb3),
    idWeb4: numOrZero(fila.idWeb4),
    ctaTraspasoEntrada: numOrZero(fila.ctaTraspasoEntrada),
    ctaTraspasoSalida: numOrZero(fila.ctaTraspasoSalida),
  }
}

export const SUBFAMILIA_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
  { key: 'familiaCodigo', label: 'Familia' },
]

export function camposSubfamiliaObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  if (!String(ficha.familiaCodigo ?? '').trim()) vacios.push('familiaCodigo')
  return vacios
}

export function validarSubfamiliaObligatorios(ficha: Record<string, unknown>): string | null {
  const key = camposSubfamiliaObligatoriosVacios(ficha)[0]
  if (!key) return null
  const label = SUBFAMILIA_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
  return `El campo "${label}" es obligatorio.`
}

export function nombreFamilia(codigo: string, opciones: { value: string; label: string }[]): string {
  const opt = opciones.find((o) => o.value === codigo.trim())
  if (!opt) return ''
  const parts = opt.label.split(' - ')
  return parts.length > 1 ? parts.slice(1).join(' - ') : opt.label
}
