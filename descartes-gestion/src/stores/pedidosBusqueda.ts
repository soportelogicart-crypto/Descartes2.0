import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { PedidoResumen } from '@/types/ventas'

export type PedidosFiltrosBusqueda = {
  empresa: string
  fechaDesde: string
  fechaHasta: string
  puesto: string
  vendedor: string
  cliente: string
  situacion: string
}

/** Resultado de la ultima busqueda del listado de pedidos (para nav. en ficha). */
export const usePedidosBusquedaStore = defineStore('pedidosBusqueda', () => {
  const filtros = ref<PedidosFiltrosBusqueda | null>(null)
  const items = ref<PedidoResumen[]>([])
  const total = ref(0)
  /** Tras alta de cabecera: abrir ficha en edicion aunque RouterView remonte por :key=path. */
  const abrirEnEdicion = ref<{ empresa: string; pedido: number } | null>(null)

  function setResultado(payload: {
    filtros: PedidosFiltrosBusqueda
    items: PedidoResumen[]
    total: number
  }) {
    filtros.value = { ...payload.filtros }
    items.value = payload.items.map((i) => ({ ...i }))
    total.value = payload.total
  }

  function clave(p: Pick<PedidoResumen, 'empresa' | 'pedido'>) {
    return `${p.empresa}|${p.pedido}`
  }

  function indiceDe(p: Pick<PedidoResumen, 'empresa' | 'pedido'>) {
    const k = clave(p)
    return items.value.findIndex((i) => clave(i) === k)
  }

  function upsertResumen(resumen: PedidoResumen) {
    const i = indiceDe(resumen)
    if (i >= 0) {
      items.value[i] = { ...items.value[i], ...resumen }
    } else {
      items.value = [{ ...resumen }, ...items.value]
      total.value = Math.max(total.value + 1, items.value.length)
    }
  }

  function marcarAbrirEnEdicion(p: Pick<PedidoResumen, 'empresa' | 'pedido'>) {
    abrirEnEdicion.value = {
      empresa: p.empresa,
      pedido: p.pedido,
    }
  }

  function consumirAbrirEnEdicion(p: Pick<PedidoResumen, 'empresa' | 'pedido'>): boolean {
    const cur = abrirEnEdicion.value
    if (!cur) return false
    if (cur.empresa !== p.empresa || cur.pedido !== p.pedido) return false
    abrirEnEdicion.value = null
    return true
  }

  return {
    filtros,
    items,
    total,
    abrirEnEdicion,
    setResultado,
    indiceDe,
    upsertResumen,
    marcarAbrirEnEdicion,
    consumirAbrirEnEdicion,
  }
})
