import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'

type ConNuevo = Record<string, unknown> & { _nuevo?: boolean; codigo?: unknown }

/**
 * Estado tipico de rejilla con filtros por embudo + fila * opcional.
 */
export function useFilasConFiltro<T extends ConNuevo>(
  filterKeys: string[],
  options?: {
    puedeCrear?: Ref<boolean> | ComputedRef<boolean>
    makeNuevo?: () => T
  }
) {
  const filasTodas = ref<T[]>([]) as Ref<T[]>
  const filaNuevaDraft = ref<T | null>(null) as Ref<T | null>
  const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(filterKeys))
  const indiceSeleccionado = ref(0)

  const filas = computed<T[]>(() => {
    const datos = filasTodas.value.filter((f) => !f._nuevo)
    const filtradas = aplicarFiltrosColumnas(datos, filtros.value) as T[]
    if (options?.puedeCrear?.value && options.makeNuevo) {
      return [...filtradas, filaNuevaDraft.value ?? options.makeNuevo()]
    }
    return filtradas
  })

  watch(filas, (lista) => {
    if (indiceSeleccionado.value >= lista.length) {
      indiceSeleccionado.value = Math.max(0, lista.length - 1)
    }
  })

  function setTodas(items: T[]) {
    filasTodas.value = items
    if (options?.makeNuevo) {
      filaNuevaDraft.value = options.makeNuevo()
    }
  }

  function actualizarFila(_index: number, fila: T) {
    if (fila._nuevo) {
      filaNuevaDraft.value = { ...fila }
      return
    }
    const codigo = String(fila.codigo ?? '')
    filasTodas.value = filasTodas.value.map((f) =>
      String(f.codigo) === codigo ? ({ ...fila, _nuevo: false } as T) : f
    )
  }

  function seleccionar(index: number) {
    indiceSeleccionado.value = index
  }

  const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)

  return {
    filasTodas,
    filas,
    filtros,
    indiceSeleccionado,
    filaSeleccionada,
    setTodas,
    actualizarFila,
    seleccionar,
  }
}
