/**
 * Resolución de plantilla de etiqueta (005 / T018).
 *
 * Modelo multi-formato:
 * - N plantillas `tipo: 'etiqueta'` (cada una con widthMm×heightMm distinto).
 * - Misma impresora de puesto (`impresoraEtiquetas`).
 * - Default: `Puestos.FormatoEtiquetas` (nombre) → si vacío, plantilla `activa`
 *   de la empresa → si ninguna, esqueleto `etiqueta-std`.
 * - Al imprimir (cola/ficha): selector con todas las de tipo etiqueta; el default
 *   precarga según esta resolución.
 */
import { api } from '@/api/client'
import {
  documentosPlantillas,
  pageSizeMm,
  type DocumentoPlantilla,
} from '@/config/documentos-plantillas'
import { clonePlantilla, esqueletoPorTipo } from '@/composables/impresionDocumentoA4Shared'

export type PlantillaEtiquetaServidor = {
  id: number
  tipo: string
  nombre: string
  activa: boolean
  empresaCodigo: string
  definicion: DocumentoPlantilla
}

export type MetaImpresionEtiquetaPuesto = {
  formatoKey: 'formatoEtiquetas'
  nombreKey: 'impresoraEtiquetas'
  plantillaTipo: 'etiqueta'
  label: string
}

export const META_ETIQUETAS_PUESTO: MetaImpresionEtiquetaPuesto = {
  formatoKey: 'formatoEtiquetas',
  nombreKey: 'impresoraEtiquetas',
  plantillaTipo: 'etiqueta',
  label: 'Etiquetas artículo',
}

function asDefinicion(raw: unknown, nombre: string): DocumentoPlantilla {
  if (raw && typeof raw === 'object' && Array.isArray((raw as DocumentoPlantilla).blocks)) {
    const d = clonePlantilla(raw as DocumentoPlantilla)
    if (!d.nombre) d.nombre = nombre
    return d
  }
  const base = esqueletoPorTipo('etiqueta') || documentosPlantillas.find((p) => p.tipo === 'etiqueta')
  return base ? clonePlantilla({ ...base, nombre }) : clonePlantilla(documentosPlantillas[0])
}

/** Lista plantillas tipo etiqueta (opcionalmente filtradas por empresa). */
export async function listarPlantillasEtiqueta(empresa?: string | null): Promise<PlantillaEtiquetaServidor[]> {
  const { data } = await api.get('/api/mantenimiento/documento-plantillas', {
    params: { tipo: 'etiqueta' },
  })
  let lista = ((data?.items ?? []) as Record<string, unknown>[]).map((raw) => {
    const nombre = String(raw.nombre ?? '')
    return {
      id: Number(raw.id),
      tipo: String(raw.tipo ?? 'etiqueta'),
      nombre,
      activa: Boolean(raw.activa),
      empresaCodigo: String(raw.empresaCodigo ?? '').trim(),
      definicion: asDefinicion(raw.definicion, nombre),
    }
  })
  const emp = String(empresa ?? '').trim().toUpperCase()
  if (emp) {
    const deEmpresa = lista.filter((p) => p.empresaCodigo.toUpperCase() === emp)
    if (deEmpresa.length > 0) lista = deEmpresa
  }
  return lista
}

export type ResolverPlantillaEtiquetaOpts = {
  /** Nombre preferido (puesto.formatoEtiquetas o elección UI). */
  nombrePreferido?: string | null
  /** Id explícito (selector de impresión). */
  plantillaId?: number | null
}

/**
 * Elige plantilla: id → nombre → activa → primera → esqueleto std.
 */
export function resolverPlantillaEtiqueta(
  lista: PlantillaEtiquetaServidor[],
  opts: ResolverPlantillaEtiquetaOpts = {}
): DocumentoPlantilla {
  const id = opts.plantillaId != null && opts.plantillaId > 0 ? opts.plantillaId : null
  if (id != null) {
    const byId = lista.find((p) => p.id === id)
    if (byId) return clonePlantilla(byId.definicion)
  }

  const nombre = String(opts.nombrePreferido ?? '').trim()
  if (nombre) {
    const byName = lista.find((p) => p.nombre.trim().toLowerCase() === nombre.toLowerCase())
    if (byName) return clonePlantilla(byName.definicion)
  }

  const activa = lista.find((p) => p.activa)
  if (activa) return clonePlantilla(activa.definicion)

  if (lista[0]) return clonePlantilla(lista[0].definicion)

  const sk = esqueletoPorTipo('etiqueta')
  if (sk) return sk
  throw new Error('No hay plantilla de etiqueta disponible')
}

/** Opciones para un select de formatos (nombre + tamaño). */
export function opcionesFormatoEtiqueta(lista: PlantillaEtiquetaServidor[]): {
  id: number
  nombre: string
  label: string
  widthMm: number
  heightMm: number
  activa: boolean
}[] {
  return lista.map((p) => {
    const size = pageSizeMm(p.definicion)
    return {
      id: p.id,
      nombre: p.nombre,
      label: `${p.nombre} (${size.widthMm}×${size.heightMm} mm)${p.activa ? ' · activa' : ''}`,
      widthMm: size.widthMm,
      heightMm: size.heightMm,
      activa: p.activa,
    }
  })
}

export async function cargarFormatoEtiquetasPuesto(puestoCodigo: string): Promise<string> {
  const { data } = await api.get(
    `/api/mantenimiento/puestos-trabajo/${encodeURIComponent(puestoCodigo)}`
  )
  return String(data?.formatoEtiquetas ?? '').trim()
}
