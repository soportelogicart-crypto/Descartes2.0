export type VentaResumen = {
  empresa: string
  tipo: string
  albaran: number
  fecha: string | null
  cliente: string | null
  razonSocial: string | null
  puesto: string | null
  vendedor: string | null
  estado: string | null
  importe: number
  facturada: boolean
  bloqueado?: boolean
  /** Ticket tipificado (FacturaTipo=T y Factura>0): no editable, puede pasar a factura. */
  esTicket?: boolean
  factura: number | null
  facturaTipo: string | null
  sesion: number | null
  impreso?: boolean
}

export type VentaLinea = {
  nroLin?: number
  articulo: string | null
  descripcion: string | null
  loteVenta?: string | null
  cantidad: number
  precio: number
  pjeDto: number
  importe: number
  pjeIva?: number
}

export type VentaDetalle = VentaResumen & {
  razonSocial2?: string | null
  nif?: string | null
  representante?: string | null
  transporte?: string | null
  direccionEnvio?: string | null
  poblacionEnvio?: string | null
  codigoPostalEnvio?: string | null
  provinciaEnvio?: string | null
  paisEnvio?: string | null
  telefono?: string | null
  telefono2?: string | null
  fax?: string | null
  email?: string | null
  almacen?: number | null
  pedido?: number | null
  referencia1?: string | null
  referencia2?: string | null
  numeroDeSerie?: string | null
  sujetoPasivo?: boolean
  portes?: string | null
  fechaEntrega?: string | null
  tarifa?: number | null
  bruto?: number
  descuento?: number
  iva?: number
  pjeIva1?: number
  pjeDto?: number
  formasPago: { codigo: string; importe: number }[]
  importesIva: { pjeIva: number; base: number; iva: number }[]
  lineas: VentaLinea[]
}

export type VentaPayload = {
  empresa: string
  tipo?: string
  albaran?: number
  puesto?: string | null
  cliente?: string | null
  razonSocial?: string | null
  razonSocial2?: string | null
  nif?: string | null
  fecha?: string
  vendedor?: string | null
  vendedorApertura?: string | null
  representante?: string | null
  agente?: string | null
  transporte?: string | null
  direccionEnvio?: string | null
  poblacionEnvio?: string | null
  codigoPostalEnvio?: string | null
  provinciaEnvio?: string | null
  paisEnvio?: string | null
  telefono?: string | null
  telefono2?: string | null
  fax?: string | null
  email?: string | null
  almacen?: number | null
  pedido?: number | null
  referencia1?: string | null
  referencia2?: string | null
  numeroDeSerie?: string | null
  sujetoPasivo?: boolean
  portes?: string | null
  fpago1?: string | null
  fpago2?: string | null
  formaPagoPrevista?: string | null
  impFpago1?: number | null
  impFpago2?: number | null
  actividad?: number | null
  tarifa?: number | null
  facturaTipo?: string | null
  empresaFacturacion?: string | null
  vendedorApertura?: string | null
  puestoApertura?: string | null
  pjeIva1?: number
  preciosIvaIncluido?: boolean
  lineas: VentaLinea[]
}

export type Paged<T> = {
  items: T[]
  total: number
  page: number
  pageSize: number
}

export type ArqueoLinea = {
  formaPago: string
  descripcion: string | null
  cuentaParaArqueo: boolean
  acumulado: number
  entrado: number
  diferencia?: number
  agrupacion?: number
  cantidad?: number
  cajonElectronico?: boolean
}

export type ArqueoAcciones = {
  situacion: boolean
  introducir: boolean
  repetir: boolean
  cerrar: boolean
  entrada: boolean
  salida: boolean
  leerCajon?: boolean
  imprimirTermica?: boolean
}

export type ArqueoDispositivo = {
  cajonElectronico?: boolean
  tipoCajon?: string | null
}

export type ArqueoResponse = {
  empresa: string
  puesto: string
  sesion: number
  totalArqueo: number
  totalAcumulado?: number
  totalEntrado?: number
  totalDiferencia?: number
  estado?: 'abierta' | 'abierta_arqueada' | 'cerrada' | string
  cerrada?: boolean
  arqueada?: boolean
  fechaInicio?: string | null
  fechaFin?: string | null
  cajeroArqueo?: string | null
  cajeroCierre?: string | null
  contadores?: Record<string, number>
  accionesPermitidas?: ArqueoAcciones
  dispositivo?: ArqueoDispositivo
  lineas: ArqueoLinea[]
  formaPagoEfectivoSugerida?: string | null
  ultimoMovimiento?: {
    tipo?: 'entrada' | 'salida' | string
    formaPago?: string
    importe?: number
    deltaAcumulado?: number
    acumuladoAntes?: number
    acumuladoDespues?: number
    concepto?: string
    valeCodigo?: number | null
  }
}

export type DesgloseArqueoLinea = {
  empresa: string
  tipo: string
  albaran: number
  fecha: string
  fechaCorta: string
  puesto: string
  sesion: number
  vendedor: string
  cliente: string
  razonSocial: string | null
  docTipo: string
  factura: number
  fpago1: string | null
  fpago2: string | null
  impFpago1: number
  impFpago2: number
  importe: number
  importeContado: number
  importeCredito: number
}

export type DesgloseArqueoGrupo = {
  clave: string
  etiqueta: string
  contado: number
  credito: number
  total: number
  lineas: DesgloseArqueoLinea[]
}

export type DesgloseArqueoVentasResponse = {
  formato: 'desglosado' | 'resumido' | string
  subtotal: string
  reservas: string
  albaranes: string
  filtros: Record<string, string | number>
  grupos: DesgloseArqueoGrupo[]
  lineas: DesgloseArqueoLinea[]
  totales: { contado: number; credito: number; total: number; documentos: number }
  porAgrupacion: {
    efectivo: number
    cheques: number
    tarjetas: number
    credito: number
    vales: number
    otros: number
  }
}

export type DesgloseArqueoVentasFiltros = {
  formato?: string
  subtotal?: string
  reservas?: string
  albaranes?: string
  empresaDesde?: string
  empresaHasta?: string
  puestoDesde?: string
  puestoHasta?: string
  sesionDesde?: number | string
  sesionHasta?: number | string
  fechaDesde?: string
  fechaHasta?: string
  vendedorDesde?: string
  vendedorHasta?: string
  clienteDesde?: string
  clienteHasta?: string
  albaranDesde?: number | string
  albaranHasta?: number | string
  fpagoDesde?: string
  fpagoHasta?: string
}

/** @deprecated Usar DesgloseArqueoVentasResponse (informe ventas). */
export type ArqueoDesgloseResponse = DesgloseArqueoVentasResponse

export type Anulacion = {
  fecha: string | null
  articulo: string | null
  cantidad: number
  importeLin: number
  motivo: string | null
  cajero: string | null
  puesto: string | null
  mesa: number | null
  sesion: number | null
  empresa: string | null
}

export type CobroPago = {
  tipo: 'cobro' | 'pago'
  formaPago: string
  importe: number
  fecha: string | null
  puesto: string | null
  empresa: string
  tipoAlbaran: string
  albaran: number
}

export type Vale = {
  empresa: string
  codigo: number
  cliente: string | null
  importe: number
  fecha: string | null
  fechaCaducidad: string | null
  liquidado: boolean
  fechaLiquidacion: string | null
  tipoLiquidacion: string | null
  caducado: boolean
}

export type PedidoResumen = {
  empresa: string
  pedido: number
  cliente: string | null
  razonSocial: string | null
  nif?: string | null
  fecha: string | null
  estado: string | null
  situacion: string | null
  situacionLabel?: string | null
  impreso: boolean
  importe: number
  actualizado?: boolean
  puesto?: string | null
  vendedor?: string | null
  suPedido?: string | null
}

export type PedidoLinea = {
  nroLin: number
  articulo: string | null
  descripcion: string | null
  cantidadPedida: number
  cantidadServida?: number
  cantidadAServir?: number
  pendiente?: number
  precio: number
  pjeDto?: number
  importe: number
  pjeIva?: number
  pjeRec?: number
  zona?: string | null
  loteVenta?: string | null
}

export type PedidoDetalle = PedidoResumen & {
  transporte?: string | null
  direccionEnvio?: string | null
  poblacionEnvio?: string | null
  codigoPostalEnvio?: string | null
  provinciaEnvio?: string | null
  paisEnvio?: string | null
  email?: string | null
  observaciones?: string | null
  fechaPrevista?: string | null
  importeBase1?: number
  importeIva1?: number
  pjeIva1?: number
  importePreparacion?: number
  pedidosWeb?: boolean
  lineas: PedidoLinea[]
  editable?: boolean
  convertible?: boolean
  ventaAsociada?: { tipo: string; albaran: number } | null
}

export type AbcVentasFiltros = {
  dimension: 'vendedores'
  orden: 'margen' | 'importe' | 'cantidad'
  idioma?: string
  divisa?: string
  iva: 'incluido' | 'excluido'
  imArticulos: boolean
  valor: 'precioMedio' | 'precioUltimo'
  tipoVenta: string
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
  agenteDesde?: string
  agenteHasta?: string
  representanteDesde?: string
  representanteHasta?: string
  vendedorDesde?: string
  vendedorHasta?: string
  clienteDesde?: string
  clienteHasta?: string
  proveedorDesde?: string
  proveedorHasta?: string
  seccionDesde?: string
  seccionHasta?: string
  subSeccionDesde?: string
  subSeccionHasta?: string
  actividadDesde?: string
  actividadHasta?: string
  tipoDescuentoDesde?: string
  tipoDescuentoHasta?: string
  tarifa?: number | string
}

export type AbcVentasArticulo = {
  codigo: string
  descripcion: string
  unidades: number
  dto: number
  importe: number
  coste: number
  margen: number
  pjeMargen: number
  pjeSobreTotal: number
  mAgr: number
}

export type AbcVentasTotales = {
  unidades: number
  dto: number
  importe: number
  coste: number
  margen: number
  pjeMargen?: number
  pjeSobreTotal?: number
}

export type AbcVentasGrupo = {
  codigo: string
  nombre: string
  totales: AbcVentasTotales
  articulos: AbcVentasArticulo[]
}

export type AbcVentasResponse = {
  dimension: string
  orden: string
  valor: string
  iva: string
  imArticulos: boolean
  tipoVenta: string
  divisa?: string
  fechaDesde: string
  fechaHasta: string
  totales: AbcVentasTotales
  grupos: AbcVentasGrupo[]
}
