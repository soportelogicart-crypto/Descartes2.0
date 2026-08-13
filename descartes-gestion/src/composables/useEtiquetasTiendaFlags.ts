/**
 * Flags de tienda para etiquetas (005 / T028).
 * Columnas Empresas: ImpEtiquetasSinEans, ImpEtiquetasSoloEansPropios, EtiquetasIvaIncluido.
 */
import { cargarTienda } from '@/composables/impresionDocumentoA4Shared'
import type { EtiquetasTiendaFlags } from '@/types/etiquetas'

function flagOn(v: unknown): boolean {
  if (v === true || v === 1 || v === '1') return true
  if (typeof v === 'string' && v.trim().toLowerCase() === 'true') return true
  const n = Number(v)
  return Number.isFinite(n) && n !== 0
}

export function flagsEtiquetasDesdeTienda(tienda: Record<string, unknown>): EtiquetasTiendaFlags {
  return {
    impEtiquetasSinEans: flagOn(tienda.impEtiquetasSinEans),
    etiquetasIvaIncluido: flagOn(tienda.etiquetasIvaIncluido),
    impEtiquetasSoloEansPropios: flagOn(tienda.impEtiquetasSoloEansPropios),
  }
}

export const FLAGS_ETIQUETAS_DEFAULT: EtiquetasTiendaFlags = {
  impEtiquetasSinEans: false,
  etiquetasIvaIncluido: false,
  impEtiquetasSoloEansPropios: false,
}

export async function cargarFlagsEtiquetasTienda(
  empresaCodigo?: string | null
): Promise<EtiquetasTiendaFlags> {
  const emp = String(empresaCodigo ?? '').trim()
  if (!emp) return { ...FLAGS_ETIQUETAS_DEFAULT }
  try {
    const tienda = await cargarTienda(emp)
    return flagsEtiquetasDesdeTienda(tienda)
  } catch {
    return { ...FLAGS_ETIQUETAS_DEFAULT }
  }
}

export type ValidarEanEtiquetaOpts = {
  /** EAN elegido / de la línea. */
  ean?: string | null
  /** EANs del artículo (ArtBarras). Obligatorio si soloEansPropios. */
  eansPropios?: string[] | null
  flags: EtiquetasTiendaFlags
  /** Contexto para el mensaje (código artículo). */
  articulo?: string | null
}

/**
 * Valida EAN según flags de tienda. Lanza Error con mensaje claro si no cumple.
 *
 * - `impEtiquetasSoloEansPropios`: exige EAN no vacío y perteneciente a `eansPropios`.
 * - si no hay EAN y `!impEtiquetasSinEans`: bloquea.
 */
export function validarEanParaImpresionEtiqueta(opts: ValidarEanEtiquetaOpts): void {
  const ean = String(opts.ean ?? '').trim()
  const flags = opts.flags
  const art = String(opts.articulo ?? '').trim()
  const pref = art ? `Artículo ${art}: ` : ''

  if (flags.impEtiquetasSoloEansPropios) {
    if (!ean) {
      throw new Error(
        `${pref}la tienda solo permite imprimir con EAN propios; este artículo no tiene EAN`
      )
    }
    const propios = (opts.eansPropios ?? [])
      .map((e) => String(e ?? '').trim())
      .filter(Boolean)
    // Si se pasan propios, el EAN debe estar en la lista.
    // Si no se pasan (p. ej. cola ya con EAN persistido), aceptamos EAN no vacío.
    if (propios.length > 0 && !propios.includes(ean)) {
      throw new Error(
        `${pref}la tienda solo permite EAN propios del artículo; «${ean}» no está en ArtBarras`
      )
    }
    return
  }

  if (!ean && !flags.impEtiquetasSinEans) {
    throw new Error(
      `${pref}la tienda no permite imprimir etiquetas sin EAN (configure EAN o active «Imp. etiquetas sin EAN»)`
    )
  }
}

/** Mensaje corto para UI (aviso, no bloqueo de preview). */
export function avisoFlagsEtiquetas(
  flags: EtiquetasTiendaFlags,
  tieneEan: boolean
): string | null {
  if (flags.impEtiquetasSoloEansPropios && !tieneEan) {
    return 'Esta tienda solo imprime con EAN propios: elija un EAN del artículo.'
  }
  if (!tieneEan && !flags.impEtiquetasSinEans) {
    return 'Esta tienda no permite etiquetas sin EAN.'
  }
  return null
}
