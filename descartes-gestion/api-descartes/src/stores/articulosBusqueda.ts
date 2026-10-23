import { defineStore } from 'pinia'
import { ref } from 'vue'
import { clonarArticuloFila, type ArticuloFila } from '@/config/articulos-columns'

/** Resultado del listado de artículos (navegación en ficha = filas visibles del grid). */
export const useArticulosBusquedaStore = defineStore('articulosBusqueda', () => {
  /** Listado cargado del servidor (sin filtro de columna en pantalla). */
  const itemsCargados = ref<ArticuloFila[]>([])
  const totalCargados = ref(0)
  /** Filas visibles del grid: es lo que recorre la ficha (como ventas). */
  const items = ref<ArticuloFila[]>([])
  const total = ref(0)

  function indiceDe(v: { codigo?: unknown }, lista = items.value): number {
    const c = String(v.codigo ?? '').trim()
    if (!c) return -1
    return lista.findIndex((i) => String(i.codigo ?? '').trim() === c)
  }

  function setResultado(payload: { items: ArticuloFila[]; total: number }) {
    itemsCargados.value = payload.items.map((i) => clonarArticuloFila(i))
    totalCargados.value = payload.total
    // `items` = filas visibles del grid (setNavegacion), no el bloque completo del servidor.
  }

  function setNavegacion(visibles: ArticuloFila[]) {
    items.value = visibles.map((i) => clonarArticuloFila(i))
    total.value = items.value.length
  }

  function aplicarEnLista(lista: ArticuloFila[], fila: ArticuloFila): ArticuloFila[] {
    const i = indiceDe(fila, lista)
    const copia = clonarArticuloFila(fila)
    if (i >= 0) {
      const next = lista.slice()
      next[i] = copia
      return next
    }
    return [copia, ...lista]
  }

  function upsertArticulo(fila: ArticuloFila) {
    itemsCargados.value = aplicarEnLista(itemsCargados.value, fila)
    items.value = aplicarEnLista(items.value, fila)
    total.value = items.value.length
    totalCargados.value = Math.max(totalCargados.value, itemsCargados.value.length)
  }

  function quitar(codigo: string) {
    const c = String(codigo).trim()
    itemsCargados.value = itemsCargados.value.filter((i) => String(i.codigo ?? '').trim() !== c)
    items.value = items.value.filter((i) => String(i.codigo ?? '').trim() !== c)
    total.value = items.value.length
    totalCargados.value = Math.max(0, totalCargados.value - 1)
  }

  return {
    itemsCargados,
    totalCargados,
    items,
    total,
    setResultado,
    setNavegacion,
    indiceDe,
    upsertArticulo,
    quitar,
  }
})
