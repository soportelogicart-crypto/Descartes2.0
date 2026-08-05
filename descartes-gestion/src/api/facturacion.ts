import { api } from '@/api/client'
import type {
  FacturasGeneracionBody,
  FacturasGeneracionPreviewResponse,
  FacturasGeneracionResponse,
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
} from '@/types/facturacion'

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

export async function descargarFacturasPdf(body: FacturasImpresionPdfBody): Promise<Blob> {
  const { data } = await api.post('/api/facturacion/impresion/pdf', body, {
    responseType: 'blob',
  })
  return data as Blob
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
