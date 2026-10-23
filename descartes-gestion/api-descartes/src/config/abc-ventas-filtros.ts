import type { EntidadLookupId } from '@/config/entidad-lookup'
import type { AbcVentasDimensionId } from '@/config/abc-ventas-dimensiones'
import {
  abcVentasDimensionUsaIntervalosExtendidos,
  abcVentasMuestraOpcionesHoras,
  abcVentasMuestraOpcionesJerarquia,
} from '@/config/abc-ventas-opciones'

/**
 * Intervalos del informe legado VentasABC* (informes.mdb / tabla Intervalos).
 * Fuente: MaxLength1/2 + Formato1/2 + Modo.
 */
export type AbcFiltroRango = {
  label: string
  desde: string
  hasta: string
  /** MaxLength legado */
  maxLength: number
  /** Formato legado (###0, ####0, vacio=texto). */
  formato: string
  /** Modo legado: 1=fecha, 5=texto, 6=codigo numerico enmascarado, 0=texto libre */
  modo: number
  /** Buscador modal (misma tabla que mantenimiento). */
  entidad?: EntidadLookupId
}

const RANGO_MACRO_FAMILIA: AbcFiltroRango = {
  label: 'MacroFamilia',
  desde: 'macroFamiliaDesde',
  hasta: 'macroFamiliaHasta',
  maxLength: 4,
  formato: '###0',
  modo: 6,
  entidad: 'macrofamilias',
}

const RANGO_FAMILIA: AbcFiltroRango = {
  label: 'Familia',
  desde: 'familiaDesde',
  hasta: 'familiaHasta',
  maxLength: 4,
  formato: '###0',
  modo: 6,
  entidad: 'familias',
}

const RANGO_SUBFAMILIA: AbcFiltroRango = {
  label: 'Subfamilia',
  desde: 'subfamiliaDesde',
  hasta: 'subfamiliaHasta',
  maxLength: 5,
  formato: '####0',
  modo: 6,
  entidad: 'subfamilias',
}

const RANGO_AGRUPACION: AbcFiltroRango = {
  label: 'Agrupacion',
  desde: 'agrupacionDesde',
  hasta: 'agrupacionHasta',
  maxLength: 4,
  formato: '###0',
  modo: 6,
  entidad: 'agrupaciones',
}

const RANGO_ARTICULO: AbcFiltroRango = {
  label: 'Articulo',
  desde: 'articuloDesde',
  hasta: 'articuloHasta',
  maxLength: 18,
  formato: '',
  modo: 5,
  entidad: 'articulos',
}

const RANGO_TIENDA: AbcFiltroRango = {
  label: 'Tienda',
  desde: 'tiendaDesde',
  hasta: 'tiendaHasta',
  maxLength: 3,
  formato: '',
  modo: 5,
  entidad: 'tiendas',
}

const RANGO_AGENTE: AbcFiltroRango = {
  label: 'Agente',
  desde: 'agenteDesde',
  hasta: 'agenteHasta',
  maxLength: 4,
  formato: '',
  modo: 5,
  entidad: 'trabajadores',
}

const RANGO_REPRESENTANTE: AbcFiltroRango = {
  label: 'Representante',
  desde: 'representanteDesde',
  hasta: 'representanteHasta',
  maxLength: 4,
  formato: '',
  modo: 5,
  entidad: 'trabajadores',
}

const RANGO_VENDEDOR: AbcFiltroRango = {
  label: 'Vendedor',
  desde: 'vendedorDesde',
  hasta: 'vendedorHasta',
  maxLength: 4,
  formato: '',
  modo: 5,
  entidad: 'trabajadores',
}

const RANGO_CLIENTE: AbcFiltroRango = {
  label: 'Cliente',
  desde: 'clienteDesde',
  hasta: 'clienteHasta',
  maxLength: 9,
  formato: '',
  modo: 5,
  entidad: 'clientes',
}

const RANGO_PROVEEDOR: AbcFiltroRango = {
  label: 'Proveedor',
  desde: 'proveedorDesde',
  hasta: 'proveedorHasta',
  maxLength: 4,
  formato: '',
  modo: 5,
  entidad: 'proveedores',
}

const RANGO_PUESTO: AbcFiltroRango = {
  label: 'Puesto',
  desde: 'puestoDesde',
  hasta: 'puestoHasta',
  maxLength: 6,
  formato: '',
  modo: 5,
  entidad: 'puestos-trabajo',
}

/** Legacy VentasABCPer — numérico, sin lupa. */
const RANGO_SESION: AbcFiltroRango = {
  label: 'Sesion',
  desde: 'sesionDesde',
  hasta: 'sesionHasta',
  maxLength: 6,
  formato: '#####0',
  modo: 6,
}

/** VentasABC Semanal — número de semana ISO. */
const RANGO_SEMANA: AbcFiltroRango = {
  label: 'Semana',
  desde: 'semanaDesde',
  hasta: 'semanaHasta',
  maxLength: 2,
  formato: '##0',
  modo: 6,
}

const RANGO_SECCION: AbcFiltroRango = {
  label: 'Seccion',
  desde: 'seccionDesde',
  hasta: 'seccionHasta',
  maxLength: 6,
  formato: '',
  modo: 5,
  entidad: 'secciones',
}

const RANGO_SUBSECCION: AbcFiltroRango = {
  label: 'SubSeccion',
  desde: 'subSeccionDesde',
  hasta: 'subSeccionHasta',
  maxLength: 6,
  formato: '',
  modo: 5,
  entidad: 'subsecciones',
}

const RANGO_PERFIL: AbcFiltroRango = {
  label: 'Perfil',
  desde: 'perfilDesde',
  hasta: 'perfilHasta',
  maxLength: 6,
  formato: '###0',
  modo: 6,
}

const RANGO_ACTIVIDAD: AbcFiltroRango = {
  label: 'Actividad',
  desde: 'actividadDesde',
  hasta: 'actividadHasta',
  maxLength: 4,
  formato: '###0',
  modo: 6,
  entidad: 'actividades',
}

const RANGO_TIPO_DESCUENTO: AbcFiltroRango = {
  label: 'Tipo Descuento',
  desde: 'tipoDescuentoDesde',
  hasta: 'tipoDescuentoHasta',
  maxLength: 6,
  formato: '',
  modo: 0,
}

/** VentasABC (dimensión agrupaciones) — fecha de la factura asociada al albarán. */
const RANGO_FECHA_FACTURACION: AbcFiltroRango = {
  label: 'F. Facturacion',
  desde: 'fechaFacturacionDesde',
  hasta: 'fechaFacturacionHasta',
  maxLength: 10,
  formato: '',
  modo: 1,
}

/** VentasABC Art — número de factura del albarán. */
const RANGO_FACTURA: AbcFiltroRango = {
  label: 'Factura',
  desde: 'facturaDesde',
  hasta: 'facturaHasta',
  maxLength: 9,
  formato: '#######0',
  modo: 6,
}

/** VentasABCCli — tarifa maestro cliente (numérico). */
const RANGO_TARIFA: AbcFiltroRango = {
  label: 'Tarifa',
  desde: 'tarifaDesde',
  hasta: 'tarifaHasta',
  maxLength: 2,
  formato: '##0',
  modo: 6,
}

/** VentasABCCli — importe línea (neto con dto, sin IVA extra en filtro). */
const RANGO_IMPORTE: AbcFiltroRango = {
  label: 'Importe',
  desde: 'importeDesde',
  hasta: 'importeHasta',
  maxLength: 12,
  formato: '',
  modo: 0,
}

/** VentasABCCli (formulario legacy en captura). */
export const ABC_VENTAS_INTERVALOS_CLIENTES: AbcFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_AGENTE,
  RANGO_TARIFA,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_PUESTO,
  RANGO_TIPO_DESCUENTO,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_VENDEDOR,
  RANGO_ACTIVIDAD,
  RANGO_IMPORTE,
]

/** VentasABC Fam / MacroFam / SubFam (formulario legacy en captura). */
export const ABC_VENTAS_INTERVALOS_JERARQUIA: AbcFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_AGENTE,
  RANGO_REPRESENTANTE,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_PUESTO,
  RANGO_SESION,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_VENDEDOR,
  RANGO_ACTIVIDAD,
  RANGO_TIPO_DESCUENTO,
]

/** VentasABC Art (dimensión artículos). */
export const ABC_VENTAS_INTERVALOS_ARTICULOS: AbcFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_AGENTE,
  RANGO_REPRESENTANTE,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_PUESTO,
  RANGO_SESION,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_VENDEDOR,
  RANGO_ACTIVIDAD,
  RANGO_TIPO_DESCUENTO,
  RANGO_FACTURA,
]

/** VentasABC — informe por agrupación de artículo (formulario legacy en captura). */
export const ABC_VENTAS_INTERVALOS_AGRUPACIONES: AbcFiltroRango[] = [
  RANGO_AGRUPACION,
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_AGENTE,
  RANGO_REPRESENTANTE,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_PUESTO,
  RANGO_SESION,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_VENDEDOR,
  RANGO_ACTIVIDAD,
  RANGO_FECHA_FACTURACION,
  RANGO_TIPO_DESCUENTO,
]

/** VentasABC Días de la semana (formulario legacy). */
export const ABC_VENTAS_INTERVALOS_DIAS_SEMANA: AbcFiltroRango[] = [
  ...ABC_VENTAS_INTERVALOS_JERARQUIA,
]

/** VentasABC Semanal (formulario legacy). */
export const ABC_VENTAS_INTERVALOS_SEMANAL: AbcFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_PUESTO,
  RANGO_SESION,
  RANGO_SEMANA,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_VENDEDOR,
  RANGO_ACTIVIDAD,
  RANGO_TIPO_DESCUENTO,
]

/** VentasABCVen y resto de dimensiones habituales. */
export const ABC_VENTAS_INTERVALOS_VENDEDORES: AbcFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_AGENTE,
  RANGO_REPRESENTANTE,
  RANGO_VENDEDOR,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_ACTIVIDAD,
  RANGO_TIPO_DESCUENTO,
]

/** VentasABC Secciones / SubSecciones (mismo formulario legacy). */
export const ABC_VENTAS_INTERVALOS_SECCIONES_SUBSECCIONES: AbcFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_AGENTE,
  RANGO_REPRESENTANTE,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_PUESTO,
  RANGO_SESION,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_VENDEDOR,
  RANGO_ACTIVIDAD,
  RANGO_TIPO_DESCUENTO,
]

/** @deprecated alias */
export const ABC_VENTAS_INTERVALOS_SUBSECCIONES = ABC_VENTAS_INTERVALOS_SECCIONES_SUBSECCIONES

export const ABC_VENTAS_INTERVALOS_SECCIONES = ABC_VENTAS_INTERVALOS_SECCIONES_SUBSECCIONES

/** VentasABCPer (perfiles). */
export const ABC_VENTAS_INTERVALOS_PERFILES: AbcFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_AGENTE,
  RANGO_REPRESENTANTE,
  RANGO_CLIENTE,
  RANGO_PROVEEDOR,
  RANGO_PUESTO,
  RANGO_SESION,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_PERFIL,
  RANGO_VENDEDOR,
  RANGO_TIPO_DESCUENTO,
]

/** @deprecated Use abcVentasIntervalosPorDimension */
export const abcVentasFiltrosRangos = ABC_VENTAS_INTERVALOS_VENDEDORES

export function abcVentasIntervalosPorDimension(dimension: string): AbcFiltroRango[] {
  const key = dimension.trim().toLowerCase() as AbcVentasDimensionId
  if (key === 'agrupaciones') {
    return ABC_VENTAS_INTERVALOS_AGRUPACIONES
  }
  if (key === 'articulos') {
    return ABC_VENTAS_INTERVALOS_ARTICULOS
  }
  if (key === 'semanal') {
    return ABC_VENTAS_INTERVALOS_SEMANAL
  }
  if (key === 'dias-semana') {
    return ABC_VENTAS_INTERVALOS_DIAS_SEMANA
  }
  if (abcVentasMuestraOpcionesJerarquia(key) || abcVentasMuestraOpcionesHoras(key)) {
    return ABC_VENTAS_INTERVALOS_JERARQUIA
  }
  if (key === 'clientes') {
    return ABC_VENTAS_INTERVALOS_CLIENTES
  }
  if (key === 'perfiles') {
    return ABC_VENTAS_INTERVALOS_PERFILES
  }
  if (abcVentasDimensionUsaIntervalosExtendidos(key)) {
    return ABC_VENTAS_INTERVALOS_SECCIONES_SUBSECCIONES
  }
  return ABC_VENTAS_INTERVALOS_VENDEDORES
}

/** Ancho visual del input en ch (acortado respecto a un campo full-width). */
export function abcFiltroInputCh(maxLength: number): number {
  return Math.min(Math.max(maxLength + 1, 4), 20)
}

/** Solo digitos si el formato legado es mascara numerica (###0 / ####0 / #####0). */
export function abcFiltroSoloDigitos(formato: string): boolean {
  return /^[#0]+$/.test(formato.trim())
}
