/** Tipos del módulo Etiquetas (005). Mapeo API camelCase ↔ SQL en data-model.md */

export type Paged<T> = {
  items: T[]
  total: number
  page: number
  pageSize: number
}

/**
 * Línea de cola — tabla `EtiquetasArticulo` (PK: articulo + nroLin).
 * `cantidad` = copias (SQL `Etiquetas`); no confundir con `cantidadStock` (SQL `Cantidad`).
 */
export type EtiquetaColaLinea = {
  articulo: string
  nroLin: number
  /** EAN como string de dígitos (SQL float legacy). */
  ean: string | null
  /** Copias a imprimir (SQL `Etiquetas`). */
  cantidad: number
  /** Stock legado (SQL `Cantidad`). */
  cantidadStock?: number | null
  precio: number
  precioVentaTeorico?: number | null
  /** Máx. ~50 chars en SQL (nchar). */
  descripcion: string | null
  lote?: string | null
  puesto?: string | null
  /** p. ej. `'C'` compra; blank/espacio si manual. */
  tipoDocumento?: string | null
  /** Tienda origen (`Empresas.Codigo`). */
  empresa?: string | null
  albaran?: number | null
  fechaDocumento?: string | null
  proveedor?: string | null
  fechaOferta?: string | null
  /** Legado; v1 suele ignorar. */
  genStock?: string | null
  numEti?: number | null
  numEtiEnHoja?: number | null
}

/** Alias canónico del spec (EtiquetaEnCola). */
export type EtiquetaEnCola = EtiquetaColaLinea

export type EtiquetaColaListParams = {
  puesto?: string
  empresa?: string
  page?: number
  pageSize?: number
}

/** Alta de línea en cola (POST). */
export type EtiquetaColaPayload = {
  articulo: string
  ean?: string | null
  /** Copias ≥ 1. */
  cantidad: number
  precio?: number
  descripcion?: string | null
  lote?: string | null
  puesto?: string | null
  empresa?: string | null
  albaran?: number | null
  tipoDocumento?: string | null
  proveedor?: string | null
  fechaDocumento?: string | null
}

/** Actualización parcial (PUT); articulo/nroLin van en la URL. */
export type EtiquetaColaUpdatePayload = Partial<
  Omit<EtiquetaColaPayload, 'articulo'>
> & {
  cantidad?: number
}

export type EtiquetaImprimirLineaRef = {
  articulo: string
  nroLin: number
}

export type EtiquetaImprimirPayload = {
  lineas: EtiquetaImprimirLineaRef[]
}

export type EtiquetaImprimirResultado = {
  eliminadas: number
}

export type EtiquetaDesdeAlbaranPayload = {
  empresa: string
  albaran: number
  puesto?: string
}

export type EtiquetaDesdeAlbaranResultado = {
  items: EtiquetaColaLinea[]
  omitidas: number
}

/** Datos para render de plantilla / preview (US4). */
export type EtiquetaPreviewDatos = {
  codigo: string
  descripcion: string | null
  ean: string | null
  precio: number
  lote?: string | null
  cantidad?: number
  puesto?: string | null
}

export type EtiquetaPreviewParams = {
  articulo: string
  ean?: string
  nroLin?: number
}

/** Flags de tienda relevantes para etiquetas (data-model §4). */
export type EtiquetasTiendaFlags = {
  impEtiquetasSinEans: boolean
  etiquetasIvaIncluido: boolean
  impEtiquetasSoloEansPropios: boolean
}

/** Impresora del puesto (impresión Electron `printLabel`). */
export type EtiquetasPuestoConfig = {
  impresoraEtiquetas: string | null
}
