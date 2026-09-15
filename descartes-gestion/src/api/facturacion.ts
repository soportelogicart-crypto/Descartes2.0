import { api } from '@/api/client'
import type { VentaDetalle, VentaResumen } from '@/types/ventas'
import type {
  AlbaranPeriodicoListItem,
  AlbaranPeriodicoWrite,
  AlbaranesPeriodicosListResponse,
  GenerarAlbaranPeriodicoResponse,
  FacturasGeneracionBody,
  FacturasGeneracionPreviewResponse,
  FacturasGeneracionResponse,
  FacturaDocumento,
  FacturasImpresionListResponse,
  FacturasImpresionPdfBody,
  FacturasManualGenerarBody,
  FacturasManualGenerarResponse,
  FacturasManualPendientesResponse,
  FacturasManualPeriodicosBody,
  FacturasManualPeriodicosResponse,
  FacturasManualTraspasoBody,
  FacturasManualTraspasoResponse,
  FacturasDiarioListResponse,
  FacturasDiarioPdfBody,
  FacturasRetrocesoBody,
  FacturasRetrocesoPreview,
  FacturasRetrocesoResponse,
  FacturasTraspasoContableListResponse,
  FacturasTraspasoContableResponse,
  RecibosImpresionResponse,
} from '@/types/facturacion'

export async function listarTraspasoContable(
  params: Record<string, string | undefined>
): Promise<FacturasTraspasoContableListResponse> {
  const { data } = await api.get<FacturasTraspasoContableListResponse>(
    '/api/facturacion/contabilidad/pendientes',
    { params }
  )
  return data
}

export async function ejecutarTraspasoContable(
  facturas: Array<{ empresa: string; facturaTipo: string; factura: number }>
): Promise<FacturasTraspasoContableResponse> {
  const { data } = await api.post<FacturasTraspasoContableResponse>(
    '/api/facturacion/contabilidad/traspasar',
    { facturas }
  )
  return data
}

export async function listarFacturasManualPendientes(
  params: Record<string, string | number | undefined>
): Promise<FacturasManualPendientesResponse> {
  const { data } = await api.get<FacturasManualPendientesResponse>(
    '/api/facturacion/manual/pendientes',
    { params }
  )
  return data
}

export async function generarFacturasManual(
  body: FacturasManualGenerarBody
): Promise<FacturasManualGenerarResponse> {
  const { data } = await api.post<FacturasManualGenerarResponse>(
    '/api/facturacion/manual/generar',
    body
  )
  return data
}

export async function previewGeneracionFacturas(
  params: Record<string, string | number | undefined>
): Promise<FacturasGeneracionPreviewResponse> {
  const { data } = await api.get<FacturasGeneracionPreviewResponse>(
    '/api/facturacion/generar/preview',
    { params }
  )
  return data
}

export async function generarFacturasAutomatico(
  body: FacturasGeneracionBody
): Promise<FacturasGeneracionResponse> {
  const { data } = await api.post<FacturasGeneracionResponse>('/api/facturacion/generar', body)
  return data
}

export async function listarFacturasImpresion(
  params: Record<string, string | number | undefined>
): Promise<FacturasImpresionListResponse> {
  const { data } = await api.get<FacturasImpresionListResponse>('/api/facturacion/impresion', {
    params,
  })
  return data
}

export async function obtenerFacturaDocumento(
  empresa: string,
  facturaTipo: string,
  factura: number,
  origen: 'impresion' | 'manual' = 'impresion'
): Promise<FacturaDocumento> {
  const raiz = origen === 'manual' ? 'manual/documento' : 'impresion/documento'
  const { data } = await api.get<FacturaDocumento>(
    `/api/facturacion/${raiz}/${encodeURIComponent(empresa)}/${encodeURIComponent(facturaTipo)}/${factura}`
  )
  return data
}

export type FacturasEmailResultado = {
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

export async function enviarFacturasManualEmail(body: {
  facturas: Array<{ empresa: string; facturaTipo: string; factura: number; cliente?: string }>
  email?: string
}): Promise<FacturasEmailResultado> {
  const { data } = await api.post<FacturasEmailResultado>('/api/facturacion/manual/email', body)
  return data
}

export async function marcarFacturasImpresas(
  body: FacturasImpresionPdfBody
): Promise<{ marcadas: number }> {
  const { data } = await api.post<{ marcadas: number }>('/api/facturacion/impresion/marcar', body)
  return data
}

export async function traspasoFacturasManual(
  body: FacturasManualTraspasoBody
): Promise<FacturasManualTraspasoResponse> {
  const { data } = await api.post<FacturasManualTraspasoResponse>(
    '/api/facturacion/manual/traspaso',
    body
  )
  return data
}

export async function generarAlbaranesPeriodicos(
  body: FacturasManualPeriodicosBody
): Promise<FacturasManualPeriodicosResponse> {
  const { data } = await api.post<FacturasManualPeriodicosResponse>(
    '/api/facturacion/manual/periodicos/generar',
    body
  )
  return data
}

export async function listarDiarioFacturacion(
  params: Record<string, string | number | undefined>
): Promise<FacturasDiarioListResponse> {
  const { data } = await api.get<FacturasDiarioListResponse>('/api/facturacion/diario', { params })
  return data
}

export async function descargarDiarioPdf(body: FacturasDiarioPdfBody): Promise<Blob> {
  const { data } = await api.post('/api/facturacion/diario/pdf', body, { responseType: 'blob' })
  return data as Blob
}

export async function listarAlbaranesPendientesFacturar(
  params: Record<string, string | number | undefined>
): Promise<FacturasManualPendientesResponse> {
  const { data } = await api.get<FacturasManualPendientesResponse>(
    '/api/facturacion/albaranes-pendientes',
    { params }
  )
  return data
}

export async function descargarAlbaranesPendientesPdf(
  params: Record<string, string | number | undefined>
): Promise<Blob> {
  const { data } = await api.get('/api/facturacion/albaranes-pendientes/pdf', {
    params,
    responseType: 'blob',
  })
  return data as Blob
}

export async function listarRecibosImpresion(
  params: Record<string, string | number | undefined>
): Promise<RecibosImpresionResponse> {
  const { data } = await api.get<RecibosImpresionResponse>('/api/facturacion/recibos', { params })
  return data
}

export async function descargarRecibosPdf(body: {
  recibos: Array<{ empresa: string; facturaTipo: string; factura: number; recibo: number }>
}): Promise<Blob> {
  const { data } = await api.post('/api/facturacion/recibos/pdf', body, { responseType: 'blob' })
  return data as Blob
}

function rutaAlbaranPendiente(empresa: string, tipo: string, albaran: number): string {
  return `/api/facturacion/albaranes-pendientes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
}

export async function obtenerAlbaranPendiente(
  empresa: string,
  tipo: string,
  albaran: number
): Promise<VentaDetalle> {
  const { data } = await api.get<VentaDetalle>(rutaAlbaranPendiente(empresa, tipo, albaran))
  return data
}

export async function descargarAlbaranPendientePdf(
  empresa: string,
  tipo: string,
  albaran: number
): Promise<Blob> {
  const { data } = await api.get(`${rutaAlbaranPendiente(empresa, tipo, albaran)}/pdf`, {
    responseType: 'blob',
  })
  return data as Blob
}

export async function previewRetrocesoFactura(params: {
  empresa: string
  facturaTipo: string
  factura: number
}): Promise<FacturasRetrocesoPreview> {
  const { data } = await api.get<FacturasRetrocesoPreview>('/api/facturacion/retroceso/preview', {
    params,
  })
  return data
}

export async function ejecutarRetrocesoFactura(
  body: FacturasRetrocesoBody
): Promise<FacturasRetrocesoResponse> {
  const { data } = await api.post<FacturasRetrocesoResponse>('/api/facturacion/retroceso', body)
  return data
}

export async function listarAlbaranesPeriodicos(
  params: Record<string, string | number | undefined>
): Promise<AlbaranesPeriodicosListResponse> {
  const { data } = await api.get<AlbaranesPeriodicosListResponse>(
    '/api/mantenimiento/albaranes-periodicos',
    { params }
  )
  return data
}

export async function buscarPlantillasAlbaranPeriodico(
  params: Record<string, string | number | undefined>
): Promise<{ items: VentaResumen[]; total: number }> {
  const { data } = await api.get<{ items: VentaResumen[]; total: number }>(
    '/api/mantenimiento/albaranes-periodicos/plantillas',
    { params }
  )
  return data
}

export async function obtenerAlbaranPeriodico(
  empresa: string,
  tipo: string,
  albaran: number
): Promise<AlbaranPeriodicoListItem> {
  const { data } = await api.get<AlbaranPeriodicoListItem>(
    `/api/mantenimiento/albaranes-periodicos/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
  )
  return data
}

export async function crearAlbaranPeriodico(
  body: AlbaranPeriodicoWrite
): Promise<AlbaranPeriodicoListItem> {
  const { data } = await api.post<AlbaranPeriodicoListItem>(
    '/api/mantenimiento/albaranes-periodicos',
    body
  )
  return data
}

export async function actualizarAlbaranPeriodico(
  empresa: string,
  tipo: string,
  albaran: number,
  body: Pick<AlbaranPeriodicoWrite, 'periodicidad' | 'ultimaGeneracion'>
): Promise<AlbaranPeriodicoListItem> {
  const { data } = await api.put<AlbaranPeriodicoListItem>(
    `/api/mantenimiento/albaranes-periodicos/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`,
    body
  )
  return data
}

export async function eliminarAlbaranPeriodico(
  empresa: string,
  tipo: string,
  albaran: number
): Promise<void> {
  await api.delete(
    `/api/mantenimiento/albaranes-periodicos/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
  )
}

export async function generarAlbaranPeriodico(
  empresa: string,
  tipo: string,
  albaran: number,
  fechaReferencia?: string
): Promise<GenerarAlbaranPeriodicoResponse> {
  const { data } = await api.post<GenerarAlbaranPeriodicoResponse>(
    `/api/mantenimiento/albaranes-periodicos/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/generar`,
    fechaReferencia ? { fechaReferencia } : {}
  )
  return data
}
