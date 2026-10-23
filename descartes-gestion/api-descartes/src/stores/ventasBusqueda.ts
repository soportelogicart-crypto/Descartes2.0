import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { VentaResumen } from '@/types/ventas'

export type VentasFiltrosBusqueda = {
  empresa: string
  fechaDesde: string
  fechaHasta: string
  puesto: string
  vendedor: string
  cliente: string
  estado: string
  claseDocumento: string
}

export type VentasFiltrosColumna = Record<string, string>

/** Resultado de la ultima busqueda del listado de ventas (para nav. en ficha). */
export const useVentasBusquedaStore = defineStore('ventasBusqueda', () => {
  const filtros = ref<VentasFiltrosBusqueda | null>(null)
  /** Listado cargado del servidor (sin filtro de columna). */
  const itemsCargados = ref<VentaResumen[]>([])
  const totalCargados = ref(0)
  /** Filas visibles del grid: es lo que recorre la ficha. */
  const items = ref<VentaResumen[]>([])
  const total = ref(0)
  const filtrosColumna = ref<VentasFiltrosColumna>({})
  /** Tras alta de cabecera: abrir ficha en edicion aunque RouterView remonte por :key=path. */
  const abrirEnEdicion = ref<{ empresa: string; tipo: string; albaran: number } | null>(null)

  function clave(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>) {
    return `${v.empresa}|${v.tipo}|${v.albaran}`
  }

  function indiceDe(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>, lista = items.value) {
    const k = clave(v)
    return lista.findIndex((i) => clave(i) === k)
  }

  function setResultado(payload: {
    filtros: VentasFiltrosBusqueda
    items: VentaResumen[]
    total: number
  }) {
    filtros.value = { ...payload.filtros }
    itemsCargados.value = payload.items.map((i) => ({ ...i }))
    totalCargados.value = payload.total
    items.value = itemsCargados.value.map((i) => ({ ...i }))
    total.value = payload.total
  }

  function setNavegacion(visibles: VentaResumen[]) {
    items.value = visibles.map((i) => ({ ...i }))
    total.value = visibles.length
  }

  function setFiltrosColumna(next: VentasFiltrosColumna) {
    filtrosColumna.value = { ...next }
  }

  function aplicarEnLista(lista: VentaResumen[], resumen: VentaResumen): VentaResumen[] {
    const i = indiceDe(resumen, lista)
    if (i >= 0) {
      const next = lista.slice()
      next[i] = { ...resumen }
      return next
    }
    return [resumen, ...lista]
  }

  /** Tras crear/guardar: actualiza o inserta el resumen en el resultado actual. */
  function upsertResumen(resumen: VentaResumen) {
    itemsCargados.value = aplicarEnLista(itemsCargados.value, resumen)
    items.value = aplicarEnLista(items.value, resumen)
    total.value = items.value.length
    totalCargados.value = Math.max(totalCargados.value, itemsCargados.value.length)
  }

  function quitar(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>) {
    const k = clave(v)
    itemsCargados.value = itemsCargados.value.filter((i) => clave(i) !== k)
    items.value = items.value.filter((i) => clave(i) !== k)
    total.value = items.value.length
    totalCargados.value = Math.max(0, totalCargados.value - 1)
  }

  function marcarAbrirEnEdicion(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>) {
    abrirEnEdicion.value = {
      empresa: v.empresa,
      tipo: v.tipo,
      albaran: v.albaran,
    }
  }

  function consumirAbrirEnEdicion(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>): boolean {
    const p = abrirEnEdicion.value
    if (!p) return false
    if (p.empresa !== v.empresa || p.tipo !== v.tipo || p.albaran !== v.albaran) {
      return false
    }
    abrirEnEdicion.value = null
    return true
  }

  return {
    filtros,
    itemsCargados,
    totalCargados,
    items,
    total,
    filtrosColumna,
    abrirEnEdicion,
    setResultado,
    setNavegacion,
    setFiltrosColumna,
    indiceDe,
    upsertResumen,
    quitar,
    marcarAbrirEnEdicion,
    consumirAbrirEnEdicion,
  }
})
