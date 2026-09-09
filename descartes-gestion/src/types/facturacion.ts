export type FacturaManualPendiente = {
  empresa: string
  tipo: string
  albaran: number
  fecha: string
  puesto: string
  cliente: string
  clienteFacturacion: string
  razonSocial: string
  nif: string
  importe: number
  pagoACuenta: number
  sujetoPasivo: boolean
  prefactura: boolean
}

export type FacturasManualPendientesResponse = {
  items: FacturaManualPendiente[]
  totales: { albaranes: number; importe: number }
}

export type ReciboImpresionItem = {
  empresa: string
  facturaTipo: string
  factura: number
  recibo: number
  fechaFactura: string
  vencimiento: string
  cliente: string
  razonSocial: string
  nif: string
  formaPago: string
  formaPagoDescripcion: string
  importe: number
  liquidado: boolean
  remesado: boolean
}

export type RecibosImpresionResponse = {
  items: ReciboImpresionItem[]
  totales: { recibos: number; importe: number }
}

export type FacturaManualGenerada = {
  empresa: string
  facturaTipo: string
  factura: number
  cliente: string
  razonSocial?: string
  importe: number
  estado: string
  recibos?: Array<{ recibo: number; importe: number; vencimiento: string }>
  albaranes: Array<{
    empresa: string
    tipo: string
    albaran: number
    fecha?: string
    importe?: number
  }>
}

export type FacturaTraspasoContable = {
  empresa: string
  facturaTipo: 'F' | 'A'
  factura: number
  fecha: string | null
  cliente: string
  razonSocial: string
  nif: string
  importe: number
  formaPago: string
  estado: string
  numEfectos: number
}

export type FacturasTraspasoContableListResponse = {
  items: FacturaTraspasoContable[]
  totales: { facturas: number; importe: number }
}

export type FacturasTraspasoContableResponse = {
  items: Array<{
    empresa: string
    facturaTipo: string
    factura: number
    serie: string
    numeroContable: number
    asiento: boolean
    efectos: number
    recuperado: boolean
  }>
  totales: { facturas: number; asientos: number; efectos: number }
}

export type FacturasManualGenerarResponse = {
  facturas: FacturaManualGenerada[]
  totales: { facturas: number; albaranes: number; importe: number }
}

export type FacturasManualGenerarBody = {
  empresa: string
  fechaFacturacion: string
  agrupacion?: 'separar' | 'agrupar'
  formaPago?: string
  numFactura?: number
  tipoFacturacion?: 'facturas' | 'prefacturas'
  albaranes: Array<{ empresa: string; tipo: string; albaran: number }>
}

export type FacturaGeneracionGrupoPreview = {
  indice: number
  cliente: string
  razonSocial: string
  sujetoPasivo: boolean
  importe: number
  albaranes: Array<{
    empresa: string
    tipo: string
    albaran: number
    fecha: string
    importe: number
  }>
}

export type FacturasGeneracionPreviewResponse = {
  totales: { albaranes: number; importe: number; gruposEstimados: number }
  omitidosImporteMinimo: number
  grupos: FacturaGeneracionGrupoPreview[]
}

export type FacturasGeneracionBody = {
  empresa: string
  fechaFacturacion: string
  agrupacion?: 'separar' | 'agrupar'
  tipoFacturacion?: 'facturas' | 'prefacturas'
  tipoCliente?: 'normales' | 'manuales' | 'todos'
  seleccion?: 'todos' | 'con_prefactura' | 'sin_prefactura'
  importeMinimo?: number
  empresaDesde?: string
  empresaHasta?: string
  fechaDesde?: string
  fechaHasta?: string
  clienteDesde?: string
  clienteHasta?: string
  albaranDesde?: number
  albaranHasta?: number
  puestoDesde?: string
  puestoHasta?: string
  vendedorDesde?: string
  vendedorHasta?: string
  fpagoDesde?: string
  fpagoHasta?: string
}

export type FacturasGeneracionResponse = FacturasManualGenerarResponse & {
  omitidosImporteMinimo: number
  emails: {
    candidatas: number
    enviadas: number
    omitidas: number
    errores: number
    detalles: Array<{
      empresa: string
      facturaTipo: string
      factura: number
      cliente: string
      estado: 'enviada' | 'omitida' | 'error'
      destinatario?: string
      motivo?: string
    }>
  }
}

export type FacturaImpresionItem = {
  empresa: string
  facturaTipo: string
  factura: number
  fecha: string
  cliente: string
  razonSocial: string
  nif: string
  importe: number
  fpago: string
  impresa: boolean
  estado: string
  /** true solo en contado diferido TPV (no es crédito). */
  facturaContadoDiferida?: boolean
  /** Clasificación filtro: diferida | contado */
  tipoCobro?: 'diferida' | 'contado'
}

export type FacturasImpresionListResponse = {
  items: FacturaImpresionItem[]
  totales: { facturas: number; importe: number }
}

export type FacturasImpresionPdfBody = {
  facturas?: Array<{ empresa: string; facturaTipo: string; factura: number }>
  empresa?: string
  fechaDesde?: string
  fechaHasta?: string
  facturaDesde?: number
  facturaHasta?: number
  facturaTipo?: string
  soloNoImpresas?: boolean | number | string
  estado?: string
  cliente?: string
  marcarImpresa?: boolean
}

export type FacturasManualTraspasoBody = {
  empresa: string
  albaranes: Array<{ empresa: string; tipo: string; albaran: number }>
}

export type FacturasManualTraspasoResponse = {
  traspasos: Array<{
    empresa: string
    albaran: number
    empresaOrigen: string
    albaranOrigen: number
  }>
  totales: { traspasos: number; albaranes: number }
}

export type FacturasManualPeriodicosBody = {
  empresa: string
  fechaDesde: string
  fechaHasta: string
}

export type FacturasManualPeriodicosResponse = {
  generados: Array<{
    empresa: string
    tipo: string
    albaran: number
    plantilla: number
    plantillaTipo: string
    fechaPeriodo: string
  }>
  totales: { generados: number; omitidos: number }
}

export type FacturaDiarioItem = FacturaImpresionItem

export type FacturasDiarioListResponse = FacturasImpresionListResponse

/** PDF del diario: mismos filtros que el listado (informe tabular). */
export type FacturasDiarioPdfBody = {
  empresa?: string
  empresaDesde?: string
  empresaHasta?: string
  fechaDesde?: string
  fechaHasta?: string
  clienteDesde?: string
  clienteHasta?: string
  facturaDesde?: number
  facturaHasta?: number
  facturaTipo?: string
  estado?: string
  estadoImpresion?: string
  tipoCobro?: string
}

export type FacturasRetrocesoPreview = {
  empresa: string
  facturaTipo: string
  factura: number
  fecha: string
  cliente: string
  razonSocial: string
  nif: string
  importe: number
  estado: string
  trasCtb: boolean
  bloqueada: boolean
  puedeRetroceder: boolean
  modo?: 'rectificativa' | 'borrar'
  resueltoDesdeAlbaran?: number | null
  mensajeBloqueo: string | null
  avisoCtb: string | null
  avisoRectificativa?: string | null
  abonoExistente?: { empresa: string; facturaTipo: string; factura: number } | null
  albaranes: Array<{
    empresa: string
    tipo: string
    albaran: number
    fecha: string
    cliente: string
    importe: number
  }>
  totales: { albaranes: number; importeAlbaranes: number }
}

export type FacturasRetrocesoBody = {
  empresa: string
  facturaTipo: string
  factura: number
}

export type FacturasRetrocesoResponse = {
  ok: boolean
  modo?: 'rectificativa' | 'borrar'
  empresa: string
  facturaTipo: string
  factura: number
  abono?: { empresa: string; facturaTipo: string; factura: number; importe: number }
  albaranesLiberados: number
  mensaje: string
}

export type AlbaranPeriodicoListItem = {
  empresa: string
  tipo: string
  albaran: number
  periodicidad: number
  periodicidadLabel: string
  ultimaGeneracion: string | null
  proximaGeneracion: string | null
  plantillaEncontrada: boolean
  cliente: string
  razonSocial: string
  importePlantilla: number
  referencia1: string | null
}

export type AlbaranesPeriodicosListResponse = {
  items: AlbaranPeriodicoListItem[]
  total: number
  page: number
  pageSize: number
}

export type AlbaranPeriodicoWrite = {
  empresa: string
  tipo: string
  albaran: number
  periodicidad: number
  ultimaGeneracion: string
  marcarReferenciaPeriodico?: boolean
}

export type GenerarAlbaranPeriodicoResponse = {
  generado: boolean
  motivoOmision: string | null
  albaranGenerado: {
    empresa: string
    tipo: string
    albaran: number
    plantilla: number
    plantillaTipo: string
    fechaPeriodo: string
  } | null
}
