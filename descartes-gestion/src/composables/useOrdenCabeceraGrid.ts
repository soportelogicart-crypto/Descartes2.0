import { computed, ref, toValue, type MaybeRefOrGetter } from 'vue'
import {
  alternarOrdenColumna,
  aplicarOrdenColumnas,
  type OrdenColumna,
} from '@/composables/useGridColumnFilters'

type FilaGrid = Record<string, unknown> & { _nuevo?: boolean; codigo?: unknown }

/**
 * Orden al pulsar el nombre de columna. La fila * de alta se queda al final.
 * El índice que se emite al padre es el de la lista original (no la ordenada).
 */
export function useOrdenCabeceraGrid<T extends FilaGrid>(
  filasProp: MaybeRefOrGetter<T[]>,
  indiceSeleccionado: MaybeRefOrGetter<number>,
  opciones?: { dateKeys?: MaybeRefOrGetter<string[] | undefined> }
) {
  const orden = ref<OrdenColumna | null>(null)

  function clicar(key: string) {
    orden.value = alternarOrdenColumna(orden.value, key)
  }

  const filasOrdenadas = computed(() =>
    aplicarOrdenColumnas(toValue(filasProp), orden.value, {
      dateKeys: toValue(opciones?.dateKeys) ?? [],
      nuevoAlFinal: true,
    })
  )

  function indiceOriginal(fila: T): number {
    const originales = toValue(filasProp)
    if (fila._nuevo) {
      const i = originales.findIndex((f) => f._nuevo)
      return i >= 0 ? i : Math.max(0, originales.length - 1)
    }
    const i = originales.findIndex(
      (f) => !f._nuevo && String(f.codigo ?? '') === String(fila.codigo ?? '')
    )
    return i >= 0 ? i : 0
  }

  function esSeleccionada(fila: T): boolean {
    const sel = toValue(filasProp)[toValue(indiceSeleccionado)]
    if (!sel) return false
    if (fila._nuevo && sel._nuevo) return true
    if (fila._nuevo || sel._nuevo) return false
    return String(fila.codigo ?? '') === String(sel.codigo ?? '')
  }

  function indicador(fila: T): string {
    if (fila._nuevo) return '*'
    return esSeleccionada(fila) ? '>' : ''
  }

  return { orden, clicar, filasOrdenadas, indiceOriginal, esSeleccionada, indicador }
}

export function useOrdenLista() {
  const orden = ref<OrdenColumna | null>(null)

  function clicarColumna(key: string) {
    orden.value = alternarOrdenColumna(orden.value, key)
  }

  function ordenarFilas<T extends object>(
    filas: T[],
    getValue: (fila: T, key: string) => unknown,
    dateKeys: string[] = []
  ): T[] {
    return aplicarOrdenColumnas(filas, orden.value, { getValue, dateKeys })
  }

  return { orden, clicarColumna, ordenarFilas }
}
