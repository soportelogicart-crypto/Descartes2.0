/**
 * Cliente API Etiquetas (005).
 * Cola CRUD (T012), confirmar impresión (T023), desde albarán compra (T031).
 */
import { api } from '@/api/client'
import type {
  EtiquetaColaLinea,
  EtiquetaColaListParams,
  EtiquetaColaPayload,
  EtiquetaColaUpdatePayload,
  EtiquetaDesdeAlbaranPayload,
  EtiquetaDesdeAlbaranResultado,
  EtiquetaImprimirPayload,
  EtiquetaImprimirResultado,
  EtiquetaPreviewDatos,
  EtiquetaPreviewParams,
  Paged,
} from '@/types/etiquetas'

export async function pingEtiquetas(): Promise<{
  ok: boolean
  modulo: string
  message: string
}> {
  const { data } = await api.get<{ ok: boolean; modulo: string; message: string }>(
    '/api/etiquetas/ping'
  )
  return data
}

// —— Cola (`EtiquetasArticulo`) ——

/** GET /api/etiquetas — filtros opcionales puesto, empresa, page, pageSize. */
export async function listarColaEtiquetas(
  params: EtiquetaColaListParams = {}
): Promise<Paged<EtiquetaColaLinea>> {
  const { data } = await api.get<Paged<EtiquetaColaLinea>>('/api/etiquetas', {
    params: cleanParams(params),
  })
  return data
}

/**
 * POST /api/etiquetas — alta en cola.
 * Requiere `articulo` o `ean` (+ `cantidad` ≥ 1). Descripción/precio/EAN se rellenan si faltan.
 */
export async function crearLineaCola(payload: EtiquetaColaPayload): Promise<EtiquetaColaLinea> {
  const { data } = await api.post<EtiquetaColaLinea>('/api/etiquetas', payload)
  return data
}

/** PUT /api/etiquetas/{articulo}/{nroLin} — p. ej. cambiar cantidad (copias). */
export async function actualizarLineaCola(
  articulo: string,
  nroLin: number,
  payload: EtiquetaColaUpdatePayload
): Promise<EtiquetaColaLinea> {
  const { data } = await api.put<EtiquetaColaLinea>(
    `/api/etiquetas/${encodeURIComponent(articulo)}/${nroLin}`,
    payload
  )
  return data
}

/** DELETE /api/etiquetas/{articulo}/{nroLin} */
export async function eliminarLineaCola(articulo: string, nroLin: number): Promise<void> {
  await api.delete(`/api/etiquetas/${encodeURIComponent(articulo)}/${nroLin}`)
}

/** POST /api/etiquetas/eliminar-lote — quitar varias líneas sin imprimir. */
export async function eliminarLineasColaLote(
  lineas: { articulo: string; nroLin: number }[]
): Promise<{ eliminadas: number }> {
  const { data } = await api.post<{ eliminadas: number }>('/api/etiquetas/eliminar-lote', {
    lineas,
  })
  return data
}

// —— Impresión / orígenes ——

/** POST /api/etiquetas/imprimir — DELETE líneas impresas OK. */
export async function confirmarImpresionEtiquetas(
  payload: EtiquetaImprimirPayload
): Promise<EtiquetaImprimirResultado> {
  const { data } = await api.post<EtiquetaImprimirResultado>('/api/etiquetas/imprimir', payload)
  return data
}

/**
 * POST /api/etiquetas/desde-albaran-compra (005 / T031 / US5).
 * Encola una línea por artículo del albarán; `omitidas` = sin EAN / sin artículo según flags.
 */
export async function encolarDesdeAlbaranCompra(
  payload: EtiquetaDesdeAlbaranPayload
): Promise<EtiquetaDesdeAlbaranResultado> {
  const empresa = String(payload.empresa ?? '').trim()
  const albaran = Math.trunc(Number(payload.albaran) || 0)
  if (!empresa || albaran < 1) {
    throw new Error('empresa y albaran (≥ 1) son obligatorios')
  }
  const body: EtiquetaDesdeAlbaranPayload = {
    empresa,
    albaran,
  }
  const puesto = String(payload.puesto ?? '').trim()
  if (puesto) body.puesto = puesto

  const { data } = await api.post<EtiquetaDesdeAlbaranResultado>(
    '/api/etiquetas/desde-albaran-compra',
    body
  )
  return data
}

export async function previewDatosEtiqueta(
  params: EtiquetaPreviewParams
): Promise<EtiquetaPreviewDatos> {
  const { data } = await api.get<EtiquetaPreviewDatos>('/api/etiquetas/preview-datos', {
    params: cleanParams(params),
  })
  return data
}

function cleanParams(
  params: Record<string, string | number | undefined | null>
): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue
    out[k] = v
  }
  return out
}
