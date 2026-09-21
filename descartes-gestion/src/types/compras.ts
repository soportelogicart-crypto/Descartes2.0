/** Tipos del módulo Compras (004). Mapeo API camelCase ↔ SQL en data-model.md */

export type Paged<T> = {
  items: T[]
  total: number
  page: number
  pageSize: number
}

/** Situación de pedido derivada en UI (data-model §2). */
export type PedidoSituacionLabel = 'pendiente' | 'parcial' | 'servido'

// —— Albarán de compra (AlbaranesCompraCab / AlbaranesComprasLin) ——

export type AlbaranCompraResumen = {
  empresa: string
  albaran: number
  suAlbaran: string | null
  fechaAlbaran: string | null
  proveedor: string | null
  /** Join UI opcional (Proveedores.RazonSocial). */
  razonSocial?: string | null
  fpago: string | null
  importeAlb: number
  importeDtos: number
  importeIva: number
  importeRec: number
  actualizado: boolean
  albaranDevolucion: boolean
  trasCtb: boolean
  almacen: number | null
  estado: string | null
}

export type AlbaranCompraLinea = {
  nroLin?: number
  articulo: string | null
  descripcion: string | null
  cantidad: number
  precio: number
  pjeDto: number
  dto1?: number
  dto2?: number
  dto3?: number
  /** Pedido proveedor origen; 0 = ninguno. */
  pedido?: number | null
  lote?: string | null
  /** Si 0 → usar almacén de cabecera. */
  almacen?: number | null
  articuloOriginal?: string | null
  /** PVP tarifa tienda (PrecioVenN del maestro; no se persiste en la línea). */
  precioVenta?: number | null
}

export type AlbaranCompraDetalle = AlbaranCompraResumen & {
  observaciones?: string | null
  albaranDevolucionEstado?: number | null
  trasModem?: boolean
  serie?: string | null
  seleccion?: boolean
  cliente?: string | null
  proyecto?: string | null
  importeTransporte?: number
  coeficienteTransporte?: number
  brutoConTransporte?: number
  /** Tarifa de venta de la tienda (Empresas_Ges.Tarifa). */
  tarifaVenta?: number
  lUpdate?: string | null
  /** TrasCtb=0 y preferiblemente Actualizado=0. */
  editable?: boolean
  lineas: AlbaranCompraLinea[]
}

/** Alias canónico de ficha (T009). */
export type AlbaranCompra = AlbaranCompraDetalle

export type AlbaranCompraPayload = {
  empresa: string
  albaran?: number
  suAlbaran?: string | null
  fechaAlbaran?: string | null
  proveedor?: string | null
  fpago?: string | null
  observaciones?: string | null
  albaranDevolucion?: boolean
  almacen?: number | null
  serie?: string | null
  cliente?: string | null
  proyecto?: string | null
  importeTransporte?: number
  coeficienteTransporte?: number
  brutoConTransporte?: number
  lineas: AlbaranCompraLinea[]
}

export type AlbaranCompraListParams = {
  empresa?: string
  proveedor?: string
  fechaDesde?: string
  fechaHasta?: string
  almacen?: number
  albaran?: number
  suAlbaran?: string
  /** 0 = pendientes de stock; 1 = ya actualizados. */
  actualizado?: number | boolean
  page?: number
  pageSize?: number
}

// —— Pedido a proveedor (PedidosCab / PedidosLin) ——

export type PedidoProveedorResumen = {
  empresa: string
  pedido: number
  fechaPedido: string | null
  proveedor: string | null
  razonSocial?: string | null
  importe: number
  situacion: number | null
  situacionLabel?: PedidoSituacionLabel | string | null
  fechaMaxRecepcion: string | null
  vendedor: string | null
  almacen: number | null
}

export type PedidoProveedorLinea = {
  numLin?: number
  articulo: string | null
  descripcion?: string | null
  cantidadPed: number
  /** En alta suele ser 0; la API lo conserva en edición. */
  cantidadSer?: number
  precioPed: number
  precioRec?: number
  pjeDto?: number
  dto1?: number
  dto2?: number
  dto3?: number
  /** Calculado por API si se omite. */
  importe?: number
  almacen?: number | null
  /** SQL `Prevision` (datetime) — ISO o null. */
  prevision?: string | null
}

export type PedidoProveedorDetalle = PedidoProveedorResumen & {
  observaciones?: string | null
  observInternas?: string | null
  trasModem?: boolean
  preciosActualizados?: boolean
  /** SQL `PrevisionC` (datetime) — ISO o null. */
  previsionC?: string | null
  importeRec?: number | null
  pedidosWeb?: boolean
  /** false si situacionLabel === 'servido'. */
  editable?: boolean
  lineas: PedidoProveedorLinea[]
}

/** Alias canónico de ficha (T009 / tasks.md). */
export type PedidoProveedor = PedidoProveedorDetalle

export type PedidoProveedorPayload = {
  empresa: string
  pedido?: number
  fechaPedido?: string | null
  proveedor?: string | null
  fechaMaxRecepcion?: string | null
  observaciones?: string | null
  observInternas?: string | null
  vendedor?: string | null
  almacen?: number | null
  lineas: PedidoProveedorLinea[]
}

export type PedidoProveedorListParams = {
  empresa?: string
  proveedor?: string
  fechaDesde?: string
  fechaHasta?: string
  almacen?: number
  pedido?: number
  situacion?: number
  page?: number
  pageSize?: number
}

/** Líneas a recibir → genera albarán (US4). */
export type RecepcionPedidoLinea = {
  numLin: number
  cantidad: number
}

export type RecepcionPedidoPayload = {
  lineas: RecepcionPedidoLinea[]
  fechaAlbaran?: string | null
  suAlbaran?: string | null
  almacen?: number | null
  observaciones?: string | null
}

export type RecepcionPedidoResultado = {
  albaran: AlbaranCompraDetalle
  pedido: PedidoProveedorDetalle
}

// —— Factura de compra / proveedor (FacturasCompras, solo lectura v1) ——

export type FacturaCompraResumen = {
  factura: number
  suFactura: string | null
  fecha: string | null
  proveedor: string | null
  razonSocial?: string | null
  estado: string | null
  fpago: string | null
  baseImp1?: number
  baseImp2?: number
  baseImp3?: number
  pjeIva1?: number
  pjeIva2?: number
  pjeIva3?: number
}

export type FacturaCompraDetalle = FacturaCompraResumen & {
  fechaVto1?: string | null
  fechaVto2?: string | null
  fechaVto3?: string | null
  fechaVto4?: string | null
  fechaVto5?: string | null
  fechaVto6?: string | null
  importeVto1?: number
  importeVto2?: number
  importeVto3?: number
  importeVto4?: number
  importeVto5?: number
  importeVto6?: number
  estadoVto1?: string | null
  estadoVto2?: string | null
  estadoVto3?: string | null
  estadoVto4?: string | null
  estadoVto5?: string | null
  estadoVto6?: string | null
  pjeRec1?: number
  pjeRec2?: number
  pjeRec3?: number
  /** v1 solo lectura. */
  editable?: boolean
}

/** Alias canónico de ficha (T009 / tasks.md). */
export type FacturaCompra = FacturaCompraDetalle

/** Alias legacy / UI “facturas de proveedor”. */
export type FacturaProveedorResumen = FacturaCompraResumen
export type FacturaProveedorDetalle = FacturaCompraDetalle

export type FacturaCompraListParams = {
  proveedor?: string
  fechaDesde?: string
  fechaHasta?: string
  factura?: number
  suFactura?: string
  estado?: string
  page?: number
  pageSize?: number
}

// —— ABC de compras ——

export type AbcComprasDimensionId =
  | 'macrofamilias'
  | 'subfamilias'
  | 'familias'
  | 'articulos'
  | 'agrupaciones'
  | 'proveedores'
  | 'secciones'
  | 'subsecciones'
  | 'almacenes'

export type AbcComprasImArticulosModo = 'si' | 'no' | 'desglosado'

export type AbcComprasFiltros = {
  dimension: AbcComprasDimensionId
  orden:
    | 'margen'
    | 'importe'
    | 'cantidad'
    | 'coste'
    | 'macrofamilias'
    | 'subfamilias'
    | 'familias'
    | 'articulos'
    | 'agrupaciones'
    | 'proveedores'
    | 'secciones'
    | 'subsecciones'
  imArticulos: AbcComprasImArticulosModo
  divisa?: 'EU' | 'PES'
  valor: 'precioMedio' | 'precioMedioActual' | 'ultimoPrecio' | 'sinValorTarifa'
  formatoJerarquia?:
    | 'normal'
    | 'extendido'
    | 'pesoKilogramos'
    | 'mediaImporte'
    | 'agrSinTotales'
    | 'agrConTotales'
  soloActualizado?: boolean
  fechaDesde: string
  fechaHasta: string
  macroFamiliaDesde?: string
  macroFamiliaHasta?: string
  familiaDesde?: string
  familiaHasta?: string
  subfamiliaDesde?: string
  subfamiliaHasta?: string
  agrupacionDesde?: string
  agrupacionHasta?: string
  articuloDesde?: string
  articuloHasta?: string
  tiendaDesde?: string
  tiendaHasta?: string
  proveedorDesde?: string
  proveedorHasta?: string
  almacenDesde?: string
  almacenHasta?: string
  seccionDesde?: string
  seccionHasta?: string
  subSeccionDesde?: string
  subSeccionHasta?: string
  loteDesde?: string
  loteHasta?: string
  ultimaVentaDesde?: string
  ultimaVentaHasta?: string
  centralDesde?: string
  centralHasta?: string
}

export type AbcComprasArticulo = {
  codigo: string
  descripcion: string
  unidades: number
  dto: number
  importe: number
  coste: number
  margen: number
  pjeMargen: number
  pjeSobreTotal?: number
  mAgr: number
}

export type AbcComprasTotales = {
  unidades: number
  dto: number
  importe: number
  coste: number
  margen: number
  pjeMargen?: number
  pjeSobreTotal?: number
}

export type AbcComprasGrupo = {
  codigo: string
  nombre: string
  totales: AbcComprasTotales
  articulos: AbcComprasArticulo[]
  /** Subfamilias + formato extendido (jerarquía superior). */
  metaFamiliaCodigo?: string
  metaFamiliaNombre?: string
  metaMacroCodigo?: string
  metaMacroNombre?: string
}

export type AbcComprasResponse = {
  dimension: string
  orden: string
  valor: string
  imArticulos: AbcComprasImArticulosModo
  divisa?: string
  formatoJerarquia?: string
  mostrarTotalGrupo?: boolean
  fechaDesde: string
  fechaHasta: string
  totales: AbcComprasTotales
  grupos: AbcComprasGrupo[]
  bloques?: null
}
