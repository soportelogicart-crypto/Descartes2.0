import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { StockListadoResult } from '@/api/listados'
import type { StockFiltroExistencias } from '@/config/stock-listado-opciones'

export type StockListadoFormState = {
  divisa: string
  tipoBusqueda: string
  imArticulos: string
  imprimirServicio: string
  stockFiltro: StockFiltroExistencias
  tarifa1: string
  tarifa2: string
  precio1: string
  precio2: string
  almacenesModo: string
  formato: string
  formatoCliente: string
  anoDesde: string
  anoHasta: string
  mesDesde: string
  mesHasta: string
  almacenDesde: string
  almacenHasta: string
  macrofamiliaDesde: string
  macrofamiliaHasta: string
  familiaDesde: string
  familiaHasta: string
  subfamiliaDesde: string
  subfamiliaHasta: string
  agrupacionDesde: string
  agrupacionHasta: string
  articuloDesde: string
  articuloHasta: string
  proveedorDesde: string
  proveedorHasta: string
  seccionDesde: string
  seccionHasta: string
  subseccionDesde: string
  subseccionHasta: string
  ultimaVentaDesde: string
  ultimaVentaHasta: string
  fechaAltaDesde: string
  fechaAltaHasta: string
  ultCompraDesde: string
  ultCompraHasta: string
  ubicacionDesde: string
  ubicacionHasta: string
}

type Sesion = {
  form: StockListadoFormState
  resultado: StockListadoResult | null
  mensaje: string | null
  error: string | null
}

/** Borrador del listado de stock por agrupación (sobrevive a KeepAlive y cambios de pestaña). */
export const useStockListadoSesionStore = defineStore('stockListadoSesion', () => {
  const porAgrupar = ref<Record<string, Sesion>>({})

  function clave(agruparPor: string): string {
    return agruparPor.trim().toLowerCase() || 'articulo'
  }

  function obtener(agruparPor: string): Sesion | null {
    return porAgrupar.value[clave(agruparPor)] ?? null
  }

  function guardar(
    agruparPor: string,
    payload: {
      form: StockListadoFormState
      resultado: StockListadoResult | null
      mensaje: string | null
      error: string | null
    },
  ) {
    porAgrupar.value[clave(agruparPor)] = {
      form: { ...payload.form },
      resultado: payload.resultado ? { ...payload.resultado, items: [...payload.resultado.items] } : null,
      mensaje: payload.mensaje,
      error: payload.error,
    }
  }

  function guardarFormulario(agruparPor: string, form: StockListadoFormState) {
    const prev = obtener(agruparPor)
    porAgrupar.value[clave(agruparPor)] = {
      form: { ...form },
      resultado: prev?.resultado ?? null,
      mensaje: prev?.mensaje ?? null,
      error: prev?.error ?? null,
    }
  }

  return { obtener, guardar, guardarFormulario }
})
