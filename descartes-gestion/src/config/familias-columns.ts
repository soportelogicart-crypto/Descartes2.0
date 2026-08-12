export type FamiliaColumnType = 'text' | 'number' | 'select-macro'

export type FamiliaColumn = {
  key: string
  label: string
  type: FamiliaColumnType
  width?: string
  readOnly?: boolean
  maxLength?: number
}

export const familiaColumns: FamiliaColumn[] = [
  { key: 'codigo', label: 'Codigo', type: 'text', width: '4.5rem', maxLength: 6 },
  { key: 'descripcion', label: 'Descripcion', type: 'text', width: '11rem', maxLength: 40 },
  { key: 'macroFamiliaCodigo', label: 'Macro', type: 'select-macro', width: '5rem' },
  { key: 'macroFamiliaNombre', label: 'Nombre', type: 'text', width: '12rem', readOnly: true },
  { key: 'cuentaCtb', label: 'Cuenta', type: 'number', width: '5.5rem' },
  { key: 'idWeb', label: 'Id', type: 'number', width: '4rem' },
  { key: 'ctaTraspasoEntrada', label: 'CtaEntrada', type: 'number', width: '6rem' },
  { key: 'ctaTraspasoSalida', label: 'CtaSalidas', type: 'number', width: '6rem' },
]

export type FamiliaFila = {
  codigo?: string
  descripcion?: string
  macroFamiliaCodigo?: string
  macroFamiliaNombre?: string
  cuentaCtb?: number | null
  cuentaCtbIta?: string
  idWeb?: number | null
  ctaTraspasoEntrada?: number | null
  ctaTraspasoSalida?: number | null
  _nuevo?: boolean
  _dirty?: boolean
}

export function familiaVacia(): FamiliaFila {
  return {
    codigo: '',
    descripcion: '',
    macroFamiliaCodigo: '',
    macroFamiliaNombre: '',
    cuentaCtb: 0,
    cuentaCtbIta: '',
    idWeb: 0,
    ctaTraspasoEntrada: 0,
    ctaTraspasoSalida: 0,
    _nuevo: true,
    _dirty: true,
  }
}

export function clonarFamilia(item: Record<string, unknown>): FamiliaFila {
  return {
    codigo: String(item.codigo ?? '').trim(),
    descripcion: String(item.descripcion ?? ''),
    macroFamiliaCodigo: String(item.macroFamiliaCodigo ?? '').trim(),
    macroFamiliaNombre: String(item.macroFamiliaNombre ?? ''),
    cuentaCtb: item.cuentaCtb == null || item.cuentaCtb === '' ? 0 : Number(item.cuentaCtb),
    cuentaCtbIta: String(item.cuentaCtbIta ?? ''),
    idWeb: item.idWeb == null || item.idWeb === '' ? 0 : Number(item.idWeb),
    ctaTraspasoEntrada:
      item.ctaTraspasoEntrada == null || item.ctaTraspasoEntrada === '' ? 0 : Number(item.ctaTraspasoEntrada),
    ctaTraspasoSalida:
      item.ctaTraspasoSalida == null || item.ctaTraspasoSalida === '' ? 0 : Number(item.ctaTraspasoSalida),
    _nuevo: false,
    _dirty: false,
  }
}

export function validarFamilia(fila: FamiliaFila): string | null {
  const codigo = String(fila.codigo ?? '').trim()
  if (!codigo) return 'El codigo es obligatorio'
  if (codigo.length > 6) return 'El codigo admite como maximo 6 caracteres'
  if (!String(fila.descripcion ?? '').trim()) return 'La descripcion es obligatoria'
  if (!String(fila.macroFamiliaCodigo ?? '').trim()) return 'La macrofamilia es obligatoria'
  return null
}

export function payloadFamilia(fila: Record<string, unknown>): Record<string, unknown> {
  return {
    codigo: String(fila.codigo ?? '').trim(),
    descripcion: String(fila.descripcion ?? '').trim(),
    macroFamiliaCodigo: String(fila.macroFamiliaCodigo ?? '').trim(),
    cuentaCtb: fila.cuentaCtb == null || fila.cuentaCtb === '' ? 0 : Number(fila.cuentaCtb),
    cuentaCtbIta: String(fila.cuentaCtbIta ?? '').trim() || null,
    idWeb: fila.idWeb == null || fila.idWeb === '' ? 0 : Number(fila.idWeb),
    ctaTraspasoEntrada:
      fila.ctaTraspasoEntrada == null || fila.ctaTraspasoEntrada === '' ? 0 : Number(fila.ctaTraspasoEntrada),
    ctaTraspasoSalida:
      fila.ctaTraspasoSalida == null || fila.ctaTraspasoSalida === '' ? 0 : Number(fila.ctaTraspasoSalida),
  }
}

export const FAMILIA_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
  { key: 'macroFamiliaCodigo', label: 'Macrofamilia' },
]

export function camposFamiliaObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  if (!String(ficha.macroFamiliaCodigo ?? '').trim()) vacios.push('macroFamiliaCodigo')
  return vacios
}

export function validarFamiliaObligatorios(ficha: Record<string, unknown>): string | null {
  const key = camposFamiliaObligatoriosVacios(ficha)[0]
  if (!key) return null
  const label = FAMILIA_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
  return `El campo "${label}" es obligatorio.`
}

export function nombreMacrofamilia(
  codigo: string,
  opciones: { value: string; label: string }[]
): string {
  const opt = opciones.find((o) => o.value === codigo.trim())
  if (!opt) return ''
  const parts = opt.label.split(' - ')
  return parts.length > 1 ? parts.slice(1).join(' - ') : opt.label
}
