/**
 * Composable: render de etiqueta HTML (005 / T021).
 * Usado por impresión cola (T022) y preview (T024).
 */
import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import type { DocumentoPreviewDatos } from '@/config/documentos-plantillas/preview-datos'
import {
  datosEtiquetaDesdeArticulo,
  datosEtiquetaDesdeCola,
  htmlEtiquetaDesdePlantilla,
  prepararRenderEtiqueta,
  type DatosArticuloEtiqueta,
  type PrepRenderEtiqueta,
} from '@/config/documentos-plantillas/etiqueta-html'
import type { EtiquetaColaLinea } from '@/types/etiquetas'
import {
  listarPlantillasEtiqueta,
  resolverPlantillaEtiqueta,
  type ResolverPlantillaEtiquetaOpts,
} from '@/composables/usePlantillaEtiqueta'

export type {
  DatosArticuloEtiqueta,
  PrepRenderEtiqueta,
} from '@/config/documentos-plantillas/etiqueta-html'

export {
  datosEtiquetaDesdeArticulo,
  datosEtiquetaDesdeCola,
  htmlEtiquetaDesdePlantilla,
  prepararRenderEtiqueta,
}

export async function renderEtiquetaDesdeCola(
  linea: EtiquetaColaLinea,
  opts?: {
    empresa?: string | null
    plantilla?: DocumentoPlantilla | null
    resolver?: ResolverPlantillaEtiquetaOpts
  }
): Promise<PrepRenderEtiqueta> {
  const datos = datosEtiquetaDesdeCola(linea)
  let plantilla = opts?.plantilla ?? null
  if (!plantilla) {
    const lista = await listarPlantillasEtiqueta(opts?.empresa ?? linea.empresa)
    plantilla = resolverPlantillaEtiqueta(lista, opts?.resolver ?? {})
  }
  return prepararRenderEtiqueta(plantilla, datos)
}

export async function renderEtiquetaDesdeArticulo(
  articulo: DatosArticuloEtiqueta,
  opts?: {
    empresa?: string | null
    plantilla?: DocumentoPlantilla | null
    resolver?: ResolverPlantillaEtiquetaOpts
  }
): Promise<PrepRenderEtiqueta> {
  const datos = datosEtiquetaDesdeArticulo(articulo)
  let plantilla = opts?.plantilla ?? null
  if (!plantilla) {
    const lista = await listarPlantillasEtiqueta(opts?.empresa)
    plantilla = resolverPlantillaEtiqueta(lista, opts?.resolver ?? {})
  }
  return prepararRenderEtiqueta(plantilla, datos)
}

/** Render síncrono si ya tienes plantilla + datos. */
export function renderEtiquetaHtml(
  plantilla: DocumentoPlantilla,
  datos: DocumentoPreviewDatos
): PrepRenderEtiqueta {
  return prepararRenderEtiqueta(plantilla, datos)
}

export function useRenderEtiqueta() {
  return {
    datosEtiquetaDesdeArticulo,
    datosEtiquetaDesdeCola,
    htmlEtiquetaDesdePlantilla,
    prepararRenderEtiqueta,
    renderEtiquetaHtml,
    renderEtiquetaDesdeCola,
    renderEtiquetaDesdeArticulo,
  }
}
