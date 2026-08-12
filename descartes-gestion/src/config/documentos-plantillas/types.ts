export type DocumentoTipo =
  | 'albaran'
  | 'factura-contado'
  | 'factura-credito'
  | 'factura-rectificativa'
  | 'ticket'

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

export type PlantillaPageFormat = 'A4' | 'ticket-80'

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
  /** Campos de datos enlazados (p.ej. cliente.nombre). */
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
    /** Ancho util en mm (ticket-80 ≈ 72 con margenes). */
    widthMm?: number
  }
  blocks: PlantillaBloque[]
}

export function esPlantillaTicket(p: Pick<DocumentoPlantilla, 'tipo' | 'page'>): boolean {
  return p.tipo === 'ticket' || p.page.format === 'ticket-80'
}
