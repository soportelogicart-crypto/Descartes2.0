import { normalizarFechaSolo } from '@/composables/useGridColumnFilters'

export type CampanaLinea = {
  nroLin: number | null
  cliente: string
  clienteNombre: string
  fechaEnvio: string
  fechaEmision: string
  asistencia: string
  observaciones: string
  vale: number | null
}

export type CampanaForm = {
  campana: string
  descripcion: string
  fecha: string
  fechaFinalizacion: string
  observaciones: string
  tipo: string
  codigo: string
  tipoCampana: number
  valeMultiple: boolean
  importeVale: number | null
  importeMinimo: number | null
  literalAviso1: string
  literalAviso2: string
  literalAviso3: string
  literalAviso4: string
  literalVale1: string
  literalVale2: string
  literalVale3: string
  literalVale4: string
  aplicarClienteVarios: boolean
  tipoAviso: number
  avisoMultiple: boolean
  tipoLiquidacion: string
  codigoLiquidacion: string
  importeLiquidacion: number | null
  tipoImporte: string
  fechaCaducidadVale: string
  fechaInicioCaducidadVale: string
  porcentajeSobreCompra: boolean
  diaSinIva: boolean
  lineas: CampanaLinea[]
}

export type CampanaFilaGrid = {
  campana?: string | number | null
  descripcion?: string
  fecha?: string | null
  fechaFinalizacion?: string | null
  _nuevo?: boolean
  _dirty?: boolean
}

function asBool(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function asFecha(value: unknown): string {
  return normalizarFechaSolo(value)
}

function asNum(value: unknown, fallback = 0): number {
  if (value == null || value === '') return fallback
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

function asNumOrNull(value: unknown): number | null {
  if (value == null || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

export function lineaVacia(): CampanaLinea {
  return {
    nroLin: null,
    cliente: '',
    clienteNombre: '',
    fechaEnvio: '',
    fechaEmision: '',
    asistencia: '',
    observaciones: '',
    vale: null,
  }
}

export function campanaVacia(): CampanaForm {
  return {
    campana: '',
    descripcion: '',
    fecha: '',
    fechaFinalizacion: '',
    observaciones: '',
    tipo: '',
    codigo: '',
    tipoCampana: 0,
    valeMultiple: false,
    importeVale: null,
    importeMinimo: null,
    literalAviso1: '',
    literalAviso2: '',
    literalAviso3: '',
    literalAviso4: '',
    literalVale1: '',
    literalVale2: '',
    literalVale3: '',
    literalVale4: '',
    aplicarClienteVarios: false,
    tipoAviso: 0,
    avisoMultiple: false,
    tipoLiquidacion: '',
    codigoLiquidacion: '',
    importeLiquidacion: null,
    tipoImporte: '',
    fechaCaducidadVale: '',
    fechaInicioCaducidadVale: '',
    porcentajeSobreCompra: false,
    diaSinIva: false,
    lineas: [],
  }
}

export function clonarCampana(item: Record<string, unknown>): CampanaForm {
  const lineasRaw = Array.isArray(item.lineas) ? item.lineas : []
  return {
    campana: item.campana == null || item.campana === '' ? '' : String(item.campana),
    descripcion: String(item.descripcion ?? ''),
    fecha: asFecha(item.fecha),
    fechaFinalizacion: asFecha(item.fechaFinalizacion),
    observaciones: String(item.observaciones ?? ''),
    tipo: String(item.tipo ?? '').trim(),
    codigo: String(item.codigo ?? '').trim(),
    tipoCampana: asNum(item.tipoCampana, 0),
    valeMultiple: asBool(item.valeMultiple),
    importeVale: asNumOrNull(item.importeVale),
    importeMinimo: asNumOrNull(item.importeMinimo),
    literalAviso1: String(item.literalAviso1 ?? ''),
    literalAviso2: String(item.literalAviso2 ?? ''),
    literalAviso3: String(item.literalAviso3 ?? ''),
    literalAviso4: String(item.literalAviso4 ?? ''),
    literalVale1: String(item.literalVale1 ?? ''),
    literalVale2: String(item.literalVale2 ?? ''),
    literalVale3: String(item.literalVale3 ?? ''),
    literalVale4: String(item.literalVale4 ?? ''),
    aplicarClienteVarios: asBool(item.aplicarClienteVarios),
    tipoAviso: asNum(item.tipoAviso, 0),
    avisoMultiple: asBool(item.avisoMultiple),
    tipoLiquidacion: String(item.tipoLiquidacion ?? '').trim(),
    codigoLiquidacion: String(item.codigoLiquidacion ?? '').trim(),
    importeLiquidacion: asNumOrNull(item.importeLiquidacion),
    tipoImporte: String(item.tipoImporte ?? '').trim(),
    fechaCaducidadVale: asFecha(item.fechaCaducidadVale),
    fechaInicioCaducidadVale: asFecha(item.fechaInicioCaducidadVale),
    porcentajeSobreCompra: asBool(item.porcentajeSobreCompra),
    diaSinIva: asBool(item.diaSinIva),
    lineas: lineasRaw.map((raw) => {
      const lin = (raw ?? {}) as Record<string, unknown>
      return {
        nroLin: asNumOrNull(lin.nroLin),
        cliente: String(lin.cliente ?? '').trim(),
        clienteNombre: String(lin.clienteNombre ?? '').trim(),
        fechaEnvio: asFecha(lin.fechaEnvio),
        fechaEmision: asFecha(lin.fechaEmision),
        asistencia: asFecha(lin.asistencia),
        observaciones: String(lin.observaciones ?? ''),
        vale: asNumOrNull(lin.vale),
      }
    }),
  }
}

export function aFilaGrid(item: Record<string, unknown>): CampanaFilaGrid {
  return {
    campana: item.campana == null ? '' : String(item.campana),
    descripcion: String(item.descripcion ?? ''),
    fecha: asFecha(item.fecha) || null,
    fechaFinalizacion: asFecha(item.fechaFinalizacion) || null,
    _nuevo: false,
    _dirty: false,
  }
}

export function filaNuevaGrid(): CampanaFilaGrid {
  return {
    campana: '',
    descripcion: '',
    fecha: null,
    fechaFinalizacion: null,
    _nuevo: true,
    _dirty: true,
  }
}

function fechaPayload(value: string): string | null {
  const f = value.trim()
  return f || null
}

export function payloadCampana(form: CampanaForm, empresa: string): Record<string, unknown> {
  return {
    empresa,
    campana: form.campana.trim() === '' ? null : Number(form.campana),
    descripcion: form.descripcion.trim(),
    fecha: fechaPayload(form.fecha),
    fechaFinalizacion: fechaPayload(form.fechaFinalizacion),
    observaciones: form.observaciones,
    tipo: form.tipo,
    codigo: form.codigo.trim(),
    tipoCampana: Number(form.tipoCampana) || 0,
    valeMultiple: Boolean(form.valeMultiple),
    importeVale: form.importeVale,
    importeMinimo: form.importeMinimo,
    literalAviso1: form.literalAviso1,
    literalAviso2: form.literalAviso2,
    literalAviso3: form.literalAviso3,
    literalAviso4: form.literalAviso4,
    literalVale1: form.literalVale1,
    literalVale2: form.literalVale2,
    literalVale3: form.literalVale3,
    literalVale4: form.literalVale4,
    aplicarClienteVarios: Boolean(form.aplicarClienteVarios),
    tipoAviso: Number(form.tipoAviso) || 0,
    avisoMultiple: Boolean(form.avisoMultiple),
    tipoLiquidacion: form.tipoLiquidacion.trim(),
    codigoLiquidacion: form.codigoLiquidacion.trim(),
    importeLiquidacion: form.importeLiquidacion,
    tipoImporte: form.tipoImporte,
    fechaCaducidadVale: fechaPayload(form.fechaCaducidadVale),
    fechaInicioCaducidadVale: fechaPayload(form.fechaInicioCaducidadVale),
    porcentajeSobreCompra: form.porcentajeSobreCompra ? 1 : 0,
    diaSinIva: Boolean(form.diaSinIva),
    filtroWhere: '',
    trasModem: false,
    diasValidez: null,
    literalAviso1G: '',
    literalAviso2G: '',
    literalVale1G: '',
    literalVale2G: '',
    lineas: form.lineas.map((lin) => ({
      nroLin: lin.nroLin,
      cliente: lin.cliente.trim(),
      clienteNombre: lin.clienteNombre.trim(),
      fechaEnvio: fechaPayload(lin.fechaEnvio),
      fechaEmision: fechaPayload(lin.fechaEmision),
      asistencia: fechaPayload(lin.asistencia),
      observaciones: lin.observaciones,
      vale: lin.vale,
    })),
  }
}

export function validarCampanaObligatorios(form: CampanaForm): string | null {
  if (!form.descripcion.trim()) return 'El campo "Descripcion" es obligatorio.'
  return null
}
