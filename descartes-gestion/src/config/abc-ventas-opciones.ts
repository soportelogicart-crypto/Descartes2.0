/** Combos legacy ABC Ventas (VentasABCVen) — etiquetas como en 1.0. */

const ORDEN_MARGEN = { value: 'margen', label: 'Margen' } as const

/** VentasABCVen y resto de dimensiones (sin margen en el combo legacy). */
export const ABC_VENTAS_ORDEN = [
  { value: 'importe', label: 'Importe' },
  { value: 'cantidad', label: 'Cantidad' },
  { value: 'coste', label: 'Coste' },
  { value: 'vendedor', label: 'Vendedor' },
] as const

/** VentasABCVen — incluye Margen. */
export const ABC_VENTAS_ORDEN_VENDEDORES = [ORDEN_MARGEN, ...ABC_VENTAS_ORDEN] as const

export type AbcVentasOrden =
  | (typeof ABC_VENTAS_ORDEN_VENDEDORES)[number]['value']
  | (typeof ABC_VENTAS_ORDEN)[number]['value']
  | AbcVentasOrdenHoras
  | AbcVentasOrdenDiasSemana

/**
 * Informes con combo Orden «Margen» y formulario extendido (puesto, sesión…).
 * VentasABCVen, Sec, SubSec, Proveedores, …
 */
export const ABC_VENTAS_DIMENSIONES_ORDEN_MARGEN = [
  'vendedores',
  'secciones',
  'subsecciones',
  'proveedores',
  'clientes',
  'agrupaciones',
  'articulos',
  'macrofamilias',
  'subfamilias',
  'familias',
] as const

/** VentasABC Fam / Macro / SubFam — mismo formulario legacy. */
export const ABC_VENTAS_DIMENSIONES_JERARQUIA = [
  'macrofamilias',
  'subfamilias',
  'familias',
] as const

export type AbcVentasDimensionJerarquia = (typeof ABC_VENTAS_DIMENSIONES_JERARQUIA)[number]

/** Combo «Formato» (VentasABC Familias). */
export const ABC_VENTAS_FORMATO_JERARQUIA = [
  { value: 'normal', label: 'Normal' },
  { value: 'pesoKilogramos', label: 'Peso Kilogramos' },
  { value: 'mediaImporte', label: 'Media Importe' },
  { value: 'agrSinTotales', label: 'Agr. sin totales' },
  { value: 'agrConTotales', label: 'Agr. con totales' },
] as const

export type AbcVentasFormatoJerarquia = (typeof ABC_VENTAS_FORMATO_JERARQUIA)[number]['value']

/** Macro / SubFam / Fam — mismos intervalos del formulario legacy. */
export function abcVentasMuestraOpcionesJerarquia(dimension: string): boolean {
  const key = dimension.trim().toLowerCase()
  return (ABC_VENTAS_DIMENSIONES_JERARQUIA as readonly string[]).includes(key)
}

/** Solo VentasABC Familias lleva combo «Formato». */
export function abcVentasMuestraFormatoFamilias(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'familias'
}

export function abcVentasEtiquetaUnidadesJerarquia(formatoJerarquia: string | undefined): string {
  const k = (formatoJerarquia ?? 'normal').trim().toLowerCase()
  if (k === 'pesokilogramos') return 'Kilos'
  if (k === 'mediaimporte') return 'Media Imp.'
  return 'Unidades'
}

/** Mismos intervalos que Secciones / Subsecciones (legacy). */
export const ABC_VENTAS_DIMENSIONES_INTERVALOS_EXTENDIDOS = [
  'secciones',
  'subsecciones',
  'proveedores',
] as const

export function abcVentasDimensionUsaIntervalosExtendidos(dimension: string): boolean {
  const key = dimension.trim().toLowerCase()
  return (ABC_VENTAS_DIMENSIONES_INTERVALOS_EXTENDIDOS as readonly string[]).includes(key)
}

/** VentasABC Proveedores — opción «Imprimir» del formulario legacy. */
export const ABC_VENTAS_IMPRIMIR_PROVEEDOR = [
  { value: 'codigo', label: 'Codigo' },
  { value: 'descripcion', label: 'Descripcion' },
] as const

export type AbcVentasImprimirProveedor = (typeof ABC_VENTAS_IMPRIMIR_PROVEEDOR)[number]['value']

export function abcVentasMuestraImprimirProveedor(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'proveedores'
}

export function abcVentasDimensionUsaOrdenMargen(dimension: string): boolean {
  const key = dimension.trim().toLowerCase()
  return (ABC_VENTAS_DIMENSIONES_ORDEN_MARGEN as readonly string[]).includes(key)
}

/** VentasABC Horas — combo Orden. */
export const ABC_VENTAS_ORDEN_HORAS = [
  { value: 'horas', label: 'Horas' },
  ORDEN_MARGEN,
  { value: 'importe', label: 'Importe' },
  { value: 'cantidad', label: 'Cantidad' },
  { value: 'coste', label: 'Coste' },
] as const

export type AbcVentasOrdenHoras = (typeof ABC_VENTAS_ORDEN_HORAS)[number]['value']

export const ABC_VENTAS_INTERVALO_HORAS = [
  { value: 'hora', label: 'Hora' },
  { value: 'mediaHora', label: '1/2 Hora' },
  { value: 'cuartoHora', label: '1/4 Hora' },
  { value: 'cincoMinutos', label: '5 Minutos' },
] as const

export type AbcVentasIntervaloHoras = (typeof ABC_VENTAS_INTERVALO_HORAS)[number]['value']

/** Legacy «Tipo» / gráfico por. */
export const ABC_VENTAS_GRAFICO_POR_HORAS = [
  { value: 'importe', label: 'Importe' },
  { value: 'unidades', label: 'Unidades' },
] as const

export type AbcVentasGraficoPorHoras = (typeof ABC_VENTAS_GRAFICO_POR_HORAS)[number]['value']

export const ABC_VENTAS_TIPO_GESTION_HORAS = [
  { value: 'abcVentasHoras', label: 'ABC de ventas por horas' },
  { value: 'ventaHoraria', label: 'Venta horaria' },
] as const

export type AbcVentasTipoGestionHoras = (typeof ABC_VENTAS_TIPO_GESTION_HORAS)[number]['value']

export const ABC_VENTAS_AGRUPACION_HORAS = [
  { value: 'familia', label: 'Familia' },
  { value: 'subfamilia', label: 'Subfamilia' },
  { value: 'agrupaciones', label: 'Agrupaciones' },
  { value: 'macrofamilias', label: 'Macrofamilias' },
  { value: 'clientes', label: 'Clientes' },
  { value: 'proveedores', label: 'Proveedores' },
  { value: 'vendedores', label: 'Vendedores' },
  { value: 'diaSemana', label: 'Dia semana' },
] as const

export type AbcVentasAgrupacionHoras = (typeof ABC_VENTAS_AGRUPACION_HORAS)[number]['value']

const AGRUPACION_HORAS_A_DIMENSION: Record<AbcVentasAgrupacionHoras, string> = {
  familia: 'familias',
  subfamilia: 'subfamilias',
  agrupaciones: 'agrupaciones',
  macrofamilias: 'macrofamilias',
  clientes: 'clientes',
  proveedores: 'proveedores',
  vendedores: 'vendedores',
  diaSemana: 'dias-semana',
}

export function abcVentasMuestraOpcionesHoras(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'horas'
}

export const ABC_VENTAS_DIA_SEMANA_ABC = [
  { value: 'todos', label: 'Todos' },
  { value: 'lunes', label: 'Lunes' },
  { value: 'martes', label: 'Martes' },
  { value: 'miercoles', label: 'Miércoles' },
  { value: 'jueves', label: 'Jueves' },
  { value: 'viernes', label: 'Viernes' },
  { value: 'sabado', label: 'Sabado' },
  { value: 'domingo', label: 'Domingo' },
] as const

export type AbcVentasDiaSemanaAbc = (typeof ABC_VENTAS_DIA_SEMANA_ABC)[number]['value']

export const ABC_VENTAS_DESGLOSE_SEMANAL = [
  { value: 'importe', label: 'Importe' },
  { value: 'unidades', label: 'Unidades' },
  { value: 'coste', label: 'Coste' },
] as const

export type AbcVentasDesgloseSemanal = (typeof ABC_VENTAS_DESGLOSE_SEMANAL)[number]['value']

export function abcVentasMuestraOpcionesSemanal(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'semanal'
}

/** VentasABC Días de la semana — combo Orden. */
export const ABC_VENTAS_ORDEN_DIAS_SEMANA = [
  { value: 'diaSemana', label: 'Dia semana' },
  ORDEN_MARGEN,
  { value: 'importe', label: 'Importe' },
  { value: 'cantidad', label: 'Cantidad' },
  { value: 'coste', label: 'Coste' },
] as const

export type AbcVentasOrdenDiasSemana = (typeof ABC_VENTAS_ORDEN_DIAS_SEMANA)[number]['value']

export const ABC_VENTAS_GRAFICO_POR_DIAS_SEMANA = [
  { value: 'importe', label: 'Importe' },
  { value: 'unidades', label: 'Unidad' },
] as const

export type AbcVentasGraficoPorDiasSemana = (typeof ABC_VENTAS_GRAFICO_POR_DIAS_SEMANA)[number]['value']

export function abcVentasMuestraOpcionesDiasSemana(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'dias-semana'
}

export function abcVentasOcultaOrdenInforme(dimension: string): boolean {
  const d = dimension.trim().toLowerCase()
  return d === 'horas' || d === 'semanal'
}

export function abcVentasDimensionGrupoDesdeAgrupacionHoras(
  agrupacion: string | undefined,
): string {
  const key = (agrupacion ?? 'familia').trim() as AbcVentasAgrupacionHoras
  return AGRUPACION_HORAS_A_DIMENSION[key] ?? 'familias'
}

export function abcVentasOrdenPorDimension(dimension: string) {
  if (abcVentasMuestraOpcionesDiasSemana(dimension)) {
    return ABC_VENTAS_ORDEN_DIAS_SEMANA
  }
  if (abcVentasMuestraOpcionesSemanal(dimension)) {
    return ABC_VENTAS_ORDEN_HORAS
  }
  if (abcVentasMuestraOpcionesHoras(dimension)) {
    return ABC_VENTAS_ORDEN_HORAS
  }
  return abcVentasDimensionUsaOrdenMargen(dimension)
    ? ABC_VENTAS_ORDEN_VENDEDORES
    : ABC_VENTAS_ORDEN
}

export function abcVentasOrdenDefectoPorDimension(dimension: string): AbcVentasOrden {
  if (abcVentasMuestraOpcionesDiasSemana(dimension)) {
    return 'diaSemana' as AbcVentasOrden
  }
  if (abcVentasMuestraOpcionesSemanal(dimension)) {
    return 'horas' as AbcVentasOrden
  }
  if (abcVentasMuestraOpcionesHoras(dimension)) {
    return 'horas' as AbcVentasOrden
  }
  return abcVentasDimensionUsaOrdenMargen(dimension) ? 'margen' : 'importe'
}

export const ABC_VENTAS_DIVISAS = [
  { value: 'EU', label: 'Eu' },
  { value: 'PES', label: 'Pes' },
] as const

export const ABC_VENTAS_IVA = [
  { value: 'incluido', label: 'Incluido' },
  { value: 'desglosado', label: 'Desglosado' },
] as const

export type AbcVentasIva = (typeof ABC_VENTAS_IVA)[number]['value']

export const ABC_VENTAS_IM_ARTICULOS = [
  { value: true, label: 'Si' },
  { value: false, label: 'No' },
] as const

export const ABC_VENTAS_VALOR = [
  { value: 'precioMedio', label: 'Precio Medio' },
  { value: 'precioMedioActual', label: 'Precio Medio Actual' },
  { value: 'ultimoPrecio', label: 'Ultimo Precio' },
  { value: 'sinValorTarifa', label: 'Sin Valor Tarifa' },
] as const

export type AbcVentasValor = (typeof ABC_VENTAS_VALOR)[number]['value']

export const ABC_VENTAS_TIPO_VENTA = [
  { value: 'todos', label: 'Todos' },
  { value: 'ticket', label: 'Tickets' },
  { value: 'facturas', label: 'Facturas' },
  { value: 'ticketFacturas', label: 'Ticket+Factura' },
  { value: 'albaranes', label: 'Albaranes' },
  { value: 'ticketsFacturasContado', label: 'Tickets+Facturas Contado' },
] as const

export type AbcVentasTipoVenta = (typeof ABC_VENTAS_TIPO_VENTA)[number]['value']

/** VentasABCCli — combo «Agrupación» (no confundir con dimensión agrupaciones). */
export const ABC_VENTAS_AGRUPACION_CLIENTES = [
  { value: 'normal', label: 'Normal' },
  { value: 'provincia', label: 'Provincia' },
  { value: 'codigoPostal', label: 'Codigo Postal' },
  { value: 'normalSaltoCliente', label: 'Normal salto x cliente' },
  { value: 'codigoPostalSaltoCliente', label: 'C.Postal x cliente' },
] as const

export type AbcVentasAgrupacionClientes = (typeof ABC_VENTAS_AGRUPACION_CLIENTES)[number]['value']

/** Formato Crystal (v1 solo informe estándar). */
export const ABC_VENTAS_FORMATO = [{ value: 'abcVentas', label: 'ABC de Ventas' }] as const

export type AbcVentasFormato = (typeof ABC_VENTAS_FORMATO)[number]['value']

export function abcVentasMuestraOpcionesClientes(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'clientes'
}

/** VentasABC Art — combo «Agrupación» (clasificación del listado, no dimensión agrupaciones). */
export const ABC_VENTAS_AGRUPACION_ARTICULOS = [
  { value: 'sinAgrupacion', label: 'Sin Agrupacion' },
  { value: 'familia', label: 'Familia' },
  { value: 'macrofamilia', label: 'MacroFamilia' },
  { value: 'subfamilia', label: 'Subfamilia' },
  { value: 'agrupacionArticulo', label: 'Agrupacion' },
] as const

export type AbcVentasAgrupacionArticulos =
  (typeof ABC_VENTAS_AGRUPACION_ARTICULOS)[number]['value']

/** VentasABC Art — combo «Formato». */
export const ABC_VENTAS_FORMATO_ARTICULOS = [
  { value: 'normal', label: 'Normal' },
  { value: 'comisiones', label: 'Comisiones' },
  { value: 'detalleComision', label: 'Detalles de comision' },
  { value: 'extendido', label: 'Extendido' },
] as const

export type AbcVentasFormatoArticulos = (typeof ABC_VENTAS_FORMATO_ARTICULOS)[number]['value']

export function abcVentasMuestraOpcionesArticulos(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'articulos'
}

export function abcVentasOcultaImArticulos(dimension: string): boolean {
  return abcVentasMuestraOpcionesArticulos(dimension)
}

export function abcVentasFormatoArticulosMuestraComision(formato: string | undefined): boolean {
  const k = (formato ?? 'normal').trim().toLowerCase()
  return k === 'comisiones' || k === 'detallecomision' || k === 'extendido'
}
