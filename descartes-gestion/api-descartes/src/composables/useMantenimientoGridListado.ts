/**
 * Exportación / impresión de grids de mantenimiento (spec 009 T050).
 * La UI está en `MantenimientoListadoButton.vue`; aquí solo utilidades.
 */
export {
  exportarGridExcel,
  exportarGridImprimir,
  filasVisiblesGrid,
  filasGridATexto,
  valorCeldaListado,
  COLUMNAS_CODIGO_DESCRIPCION,
  type ListadoGridColumnDef,
} from '@/composables/exportGridListado'
