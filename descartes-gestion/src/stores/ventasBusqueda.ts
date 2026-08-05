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
}

/** Resultado de la ultima busqueda del listado de ventas (para nav. en ficha). */
export const useVentasBusquedaStore = defineStore('ventasBusqueda', () => {
  const filtros = ref<VentasFiltrosBusqueda | null>(null)
  const items = ref<VentaResumen[]>([])
  const total = ref(0)
  /** Tras alta de cabecera: abrir ficha en edicion aunque RouterView remonte por :key=path. */
  const abrirEnEdicion = ref<{ empresa: string; tipo: string; albaran: number } | null>(null)

  function setResultado(payload: {
    filtros: VentasFiltrosBusqueda
    items: VentaResumen[]
    total: number
  }) {
    filtros.value = { ...payload.filtros }
    items.value = payload.items.map((i) => ({ ...i }))
    total.value = payload.total
  }

  function clave(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>) {
    return `${v.empresa}|${v.tipo}|${v.albaran}`
  }

  function indiceDe(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>) {
    const k = clave(v)
    return items.value.findIndex((i) => clave(i) === k)
  }

  /** Tras crear/guardar: actualiza o inserta el resumen en el resultado actual. */
  function upsertResumen(resumen: VentaResumen) {
    const i = indiceDe(resumen)
    if (i >= 0) {
      items.value[i] = { ...resumen }
    } else {
      items.value = [resumen, ...items.value]
      total.value = items.value.length
    }
  }

  function quitar(v: Pick<VentaResumen, 'empresa' | 'tipo' | 'albaran'>) {
    const i = indiceDe(v)
    if (i < 0) return
    items.value = items.value.filter((_, idx) => idx !== i)
    total.value = Math.max(0, total.value - 1)
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
    items,
    total,
    abrirEnEdicion,
    setResultado,
    indiceDe,
    upsertResumen,
    quitar,
    marcarAbrirEnEdicion,
    consumirAbrirEnEdicion,
  }
})
