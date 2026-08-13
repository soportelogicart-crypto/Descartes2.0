/**
 * Impresión de cola de etiquetas (005 / T022).
 * Render HTML → Electron `printLabel` (ImpresoraEtiquetas) → confirmar DELETE.
 */
import { confirmarImpresionEtiquetas } from '@/api/etiquetas'
import { imprimirEtiquetaViaBridge } from '@/bridge/electron'
import { extractApiError } from '@/composables/extractApiError'
import {
  cargarPuesto,
  resolverNombreImpresoraDoc,
} from '@/composables/impresionDocumentoA4Shared'
import {
  listarPlantillasEtiqueta,
  META_ETIQUETAS_PUESTO,
  opcionesFormatoEtiqueta,
  resolverPlantillaEtiqueta,
} from '@/composables/usePlantillaEtiqueta'
import {
  cargarFlagsEtiquetasTienda,
  validarEanParaImpresionEtiqueta,
} from '@/composables/useEtiquetasTiendaFlags'
import { renderEtiquetaDesdeArticulo, renderEtiquetaDesdeCola } from '@/composables/useRenderEtiqueta'
import type { DatosArticuloEtiqueta } from '@/composables/useRenderEtiqueta'
import type { EtiquetaColaLinea } from '@/types/etiquetas'

export type ResultadoImpresionCola = {
  impresas: number
  eliminadas: number
  impresora: string
  /** Si se detuvo a mitad de lote (queda el resto en cola). */
  parcial: boolean
  message: string
}

export type OptsImpresionCola = {
  puestoCodigo: string
  empresa?: string | null
  /** Id plantilla del selector; si null, default puesto/activa. */
  plantillaId?: number | null
  /** Default true: vaciar líneas OK vía POST /imprimir. */
  vaciarTrasOk?: boolean
}

/** Carga opciones de formato + default del puesto para el selector. */
export async function cargarOpcionesImpresionEtiqueta(opts: {
  puestoCodigo?: string | null
  empresa?: string | null
}): Promise<{
  opciones: ReturnType<typeof opcionesFormatoEtiqueta>
  plantillaIdDefault: number | null
  impresoraNombre: string
}> {
  const puestoCodigo = String(opts.puestoCodigo ?? '').trim()
  const empresa = opts.empresa ?? null

  const [lista, puesto] = await Promise.all([
    listarPlantillasEtiqueta(empresa),
    puestoCodigo ? cargarPuesto(puestoCodigo) : Promise.resolve({} as Record<string, unknown>),
  ])

  const formatoPreferido = String(puesto[META_ETIQUETAS_PUESTO.formatoKey] ?? '').trim()
  const plantilla = resolverPlantillaEtiqueta(lista, { nombrePreferido: formatoPreferido || null })
  const match = lista.find(
    (p) => p.nombre.trim().toLowerCase() === String(plantilla.nombre ?? '').trim().toLowerCase()
  )
  const activa = lista.find((p) => p.activa)

  const imp = await resolverNombreImpresoraDoc(
    puesto,
    META_ETIQUETAS_PUESTO.nombreKey,
    null
  )

  return {
    opciones: opcionesFormatoEtiqueta(lista),
    plantillaIdDefault: match?.id ?? activa?.id ?? lista[0]?.id ?? null,
    impresoraNombre: imp.nombre,
  }
}

/**
 * Imprime líneas de cola: N copias = `cantidad` por línea.
 * Si falla una, para y solo confirma/borra las ya impresas.
 */
export async function imprimirLineasCola(
  lineas: EtiquetaColaLinea[],
  opts: OptsImpresionCola
): Promise<ResultadoImpresionCola> {
  if (lineas.length === 0) {
    throw new Error('No hay líneas para imprimir')
  }

  const puestoCodigo = String(opts.puestoCodigo ?? '').trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir etiquetas')
  }

  const empresa = opts.empresa ?? null
  const flags = await cargarFlagsEtiquetasTienda(empresa)

  // Validar EAN de todas las líneas antes de imprimir ninguna
  for (const linea of lineas) {
    validarEanParaImpresionEtiqueta({
      ean: linea.ean,
      flags,
      articulo: linea.articulo,
    })
  }

  const [puesto, lista] = await Promise.all([
    cargarPuesto(puestoCodigo),
    listarPlantillasEtiqueta(empresa),
  ])

  const formatoPreferido = String(puesto[META_ETIQUETAS_PUESTO.formatoKey] ?? '').trim()
  const plantilla = resolverPlantillaEtiqueta(lista, {
    plantillaId: opts.plantillaId,
    nombrePreferido: formatoPreferido || null,
  })

  const imp = await resolverNombreImpresoraDoc(
    puesto,
    META_ETIQUETAS_PUESTO.nombreKey,
    null
  )
  if (!imp.nombre) {
    throw new Error(
      'No hay impresora de etiquetas en el puesto (Generales II → Etiquetas artículo)'
    )
  }

  const okRefs: { articulo: string; nroLin: number }[] = []
  let parcial = false

  for (const linea of lineas) {
    const copies = Math.max(1, Math.trunc(Number(linea.cantidad) || 1))
    try {
      const prep = await renderEtiquetaDesdeCola(linea, {
        empresa: empresa ?? linea.empresa,
        plantilla,
      })
      await imprimirEtiquetaViaBridge({
        html: prep.html,
        pageWidthMm: prep.pageWidthMm,
        pageHeightMm: prep.pageHeightMm,
        copies,
        impresora: imp.nombre,
        impresoraId: imp.id ?? undefined,
        silent: true,
      })
      okRefs.push({ articulo: linea.articulo, nroLin: linea.nroLin })
    } catch (e: unknown) {
      parcial = true
      const msg = extractApiError(e, 'Error al imprimir')
      if (okRefs.length === 0) {
        throw new Error(msg)
      }
      // Confirmar las OK y reportar parcial
      let eliminadas = 0
      if (opts.vaciarTrasOk !== false) {
        const res = await confirmarImpresionEtiquetas({ lineas: okRefs })
        eliminadas = res.eliminadas
      }
      return {
        impresas: okRefs.length,
        eliminadas,
        impresora: imp.nombre,
        parcial: true,
        message: `Impresas ${okRefs.length} de ${lineas.length}; se detuvo: ${msg}`,
      }
    }
  }

  let eliminadas = 0
  if (opts.vaciarTrasOk !== false && okRefs.length > 0) {
    const res = await confirmarImpresionEtiquetas({ lineas: okRefs })
    eliminadas = res.eliminadas
  }

  return {
    impresas: okRefs.length,
    eliminadas,
    impresora: imp.nombre,
    parcial,
    message: `Impresas ${okRefs.length} línea(s) en «${imp.nombre}»` +
      (eliminadas > 0 ? `; eliminadas de cola: ${eliminadas}` : ''),
  }
}

/**
 * Impresión rápida desde ficha artículo (005 / T026 / US1).
 * No toca la cola `EtiquetasArticulo`. Respeta flags tienda (T028).
 */
export async function imprimirArticuloRapido(
  articulo: DatosArticuloEtiqueta,
  opts: {
    puestoCodigo: string
    empresa?: string | null
    plantillaId?: number | null
    copies: number
    /** EANs ArtBarras del artículo (para ImpEtiquetasSoloEansPropios). */
    eansPropios?: string[] | null
  }
): Promise<{ impresora: string; copies: number; message: string }> {
  const puestoCodigo = String(opts.puestoCodigo ?? '').trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir etiquetas')
  }
  const codigo = String(articulo.codigo ?? '').trim()
  if (!codigo) {
    throw new Error('Artículo sin código')
  }

  const copies = Math.max(1, Math.min(500, Math.trunc(Number(opts.copies) || 1)))
  const empresa = opts.empresa ?? null

  const flags = await cargarFlagsEtiquetasTienda(empresa)
  validarEanParaImpresionEtiqueta({
    ean: articulo.ean,
    eansPropios: opts.eansPropios ?? null,
    flags,
    articulo: codigo,
  })

  const [puesto, lista] = await Promise.all([
    cargarPuesto(puestoCodigo),
    listarPlantillasEtiqueta(empresa),
  ])

  const formatoPreferido = String(puesto[META_ETIQUETAS_PUESTO.formatoKey] ?? '').trim()
  const plantilla = resolverPlantillaEtiqueta(lista, {
    plantillaId: opts.plantillaId,
    nombrePreferido: formatoPreferido || null,
  })

  const imp = await resolverNombreImpresoraDoc(
    puesto,
    META_ETIQUETAS_PUESTO.nombreKey,
    null
  )
  if (!imp.nombre) {
    throw new Error(
      'No hay impresora de etiquetas en el puesto (Generales II → Etiquetas artículo)'
    )
  }

  const prep = await renderEtiquetaDesdeArticulo(articulo, {
    empresa,
    plantilla,
  })

  await imprimirEtiquetaViaBridge({
    html: prep.html,
    pageWidthMm: prep.pageWidthMm,
    pageHeightMm: prep.pageHeightMm,
    copies,
    impresora: imp.nombre,
    impresoraId: imp.id ?? undefined,
    silent: true,
  })

  return {
    impresora: imp.nombre,
    copies,
    message: `Impresas ${copies} etiqueta(s) de ${codigo} en «${imp.nombre}»`,
  }
}

export function useImpresionEtiquetas() {
  return {
    cargarOpcionesImpresionEtiqueta,
    imprimirLineasCola,
    imprimirArticuloRapido,
  }
}
