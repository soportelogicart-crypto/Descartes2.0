export type DocumentoTipo =
  | 'albaran'
  | 'factura-contado'
  | 'factura-credito'
  | 'factura-rectificativa'
  | 'ticket'
  /** Etiqueta de artículo (005): tamaño mm configurable. */
  | 'etiqueta'

export type PlantillaBloqueTipo =
  | 'texto'
  | 'campo'
  | 'emblema'
  | 'empresa-cabecera'
  | 'titulo-documento'
  | 'bloque-cliente'
  | 'bloque-meta'
  | 'codigo-barras'
  | 'tabla-lineas'
  | 'totales-iva'
  | 'totales-ticket'
  | 'datos-bancarios'
  | 'vencimientos'
  | 'qr-verifactu'
  | 'pie'
  | 'literales-puesto'
  | 'separador'

/** `label` = página de etiqueta (widthMm × heightMm). */
export type PlantillaPageFormat = 'A4' | 'ticket-80' | 'label'

/** Posicion en mm desde la esquina superior izquierda de la pagina. */
export type PlantillaBloque = {
  id: string
  type: PlantillaBloqueTipo
  x: number
  y: number
  w: number
  h: number
  /** Etiqueta fija o plantilla de texto. */
  label?: string
  /** Campos de datos enlazados (p.ej. cliente.nombre, articulo.ean). */
  bind?: string[]
  /** Columnas de tabla cuando type = tabla-lineas. */
  columns?: { key: string; label: string; width: number }[]
  props?: Record<string, unknown>
}

export type DocumentoPlantilla = {
  id: string
  tipo: DocumentoTipo
  nombre: string
  descripcion?: string
  version: number
  page: {
    format: PlantillaPageFormat
    orientation: 'portrait' | 'landscape'
    /** Margenes en mm. */
    marginMm: { top: number; right: number; bottom: number; left: number }
    /**
     * Ancho de página en mm.
     * ticket-80 ≈ 80; etiqueta típica 50 / 60 (obligatorio si format = label).
     */
    widthMm?: number
    /**
     * Alto de página en mm (etiquetas; p. ej. 30 / 40).
     * Obligatorio si format = label.
     */
    heightMm?: number
  }
  blocks: PlantillaBloque[]
}

/** Tamaños de etiqueta habituales (mm). */
export const ETIQUETA_TAMANOS_MM = [
  { widthMm: 30, heightMm: 20, label: '30 × 20 mm' },
  { widthMm: 40, heightMm: 25, label: '40 × 25 mm' },
  { widthMm: 40, heightMm: 30, label: '40 × 30 mm' },
  { widthMm: 50, heightMm: 25, label: '50 × 25 mm' },
  { widthMm: 50, heightMm: 30, label: '50 × 30 mm' },
  { widthMm: 50, heightMm: 40, label: '50 × 40 mm' },
  { widthMm: 60, heightMm: 30, label: '60 × 30 mm' },
  { widthMm: 60, heightMm: 40, label: '60 × 40 mm' },
  { widthMm: 70, heightMm: 37, label: '70 × 37 mm' },
  { widthMm: 80, heightMm: 40, label: '80 × 40 mm' },
  { widthMm: 90, heightMm: 50, label: '90 × 50 mm' },
  { widthMm: 100, heightMm: 50, label: '100 × 50 mm' },
  { widthMm: 100, heightMm: 70, label: '100 × 70 mm' },
] as const

export type EtiquetaTamanoMm = (typeof ETIQUETA_TAMANOS_MM)[number]

function round1(n: number) {
  return Math.round(n * 10) / 10
}

/**
 * Cambia el tamaño de página de una plantilla etiqueta y escala bloques
 * proporcionalmente (mantiene la composición relativa).
 */
export function conTamanoEtiqueta(
  plantilla: DocumentoPlantilla,
  widthMm: number,
  heightMm: number
): DocumentoPlantilla {
  const w = Math.min(200, Math.max(10, round1(widthMm)))
  const h = Math.min(200, Math.max(10, round1(heightMm)))
  const fromW = Math.max(1, plantilla.page.widthMm ?? 50)
  const fromH = Math.max(1, plantilla.page.heightMm ?? 30)
  const sx = w / fromW
  const sy = h / fromH
  const same = Math.abs(sx - 1) < 0.001 && Math.abs(sy - 1) < 0.001

  const blocks = same
    ? plantilla.blocks
    : plantilla.blocks.map((b) => {
        const nx = round1(Math.max(0, b.x * sx))
        const ny = round1(Math.max(0, b.y * sy))
        const nw = round1(Math.min(w - nx, Math.max(2, b.w * sx)))
        const nh = round1(Math.min(h - ny, Math.max(2, b.h * sy)))
        return { ...b, x: nx, y: ny, w: nw, h: nh }
      })

  return {
    ...plantilla,
    page: {
      ...plantilla.page,
      format: 'label',
      orientation: plantilla.page.orientation ?? 'portrait',
      widthMm: w,
      heightMm: h,
    },
    blocks,
  }
}

/** Clave `WxH` de un tamaño predefinido, o null si es personalizado. */
export function claveTamanoEtiqueta(widthMm: number, heightMm: number): string | null {
  const hit = ETIQUETA_TAMANOS_MM.find((t) => t.widthMm === widthMm && t.heightMm === heightMm)
  return hit ? `${hit.widthMm}x${hit.heightMm}` : null
}

export function parseClaveTamanoEtiqueta(clave: string): { widthMm: number; heightMm: number } | null {
  const m = /^(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)$/i.exec(clave.trim())
  if (!m) return null
  return { widthMm: Number(m[1]), heightMm: Number(m[2]) }
}

export function esPlantillaTicket(p: Pick<DocumentoPlantilla, 'tipo' | 'page'>): boolean {
  return p.tipo === 'ticket' || p.page.format === 'ticket-80'
}

export function esPlantillaEtiqueta(p: Pick<DocumentoPlantilla, 'tipo' | 'page'>): boolean {
  return p.tipo === 'etiqueta' || p.page.format === 'label'
}

/** Dimensiones efectivas de la página en mm (fallback A4 / ticket / etiqueta). */
export function pageSizeMm(p: Pick<DocumentoPlantilla, 'tipo' | 'page'>): {
  widthMm: number
  heightMm: number
} {
  if (esPlantillaEtiqueta(p)) {
    return {
      widthMm: p.page.widthMm ?? 50,
      heightMm: p.page.heightMm ?? 30,
    }
  }
  if (esPlantillaTicket(p)) {
    return {
      widthMm: p.page.widthMm ?? 80,
      heightMm: p.page.heightMm ?? 200,
    }
  }
  const landscape = p.page.orientation === 'landscape'
  return {
    widthMm: p.page.widthMm ?? (landscape ? 297 : 210),
    heightMm: p.page.heightMm ?? (landscape ? 210 : 297),
  }
}
