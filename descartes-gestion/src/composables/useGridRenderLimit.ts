import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'

/**
 * Monta en el DOM solo las primeras filas y añade el resto al bajar el scroll,
 * para que el grid pueda tener cargado todo el resultado sin volverse lento.
 */
export function useGridRenderLimit<T>(
  filas: Ref<T[]> | ComputedRef<T[]>,
  inicial = 300,
  paso = 300
) {
  const gridEl = ref<HTMLElement | null>(null)
  const limite = ref(inicial)

  const visibles = computed(() => filas.value.slice(0, limite.value))

  function onScrollGrid() {
    const el = gridEl.value
    if (!el) return
    if (el.scrollTop + el.clientHeight < el.scrollHeight - 250) return
    if (limite.value < filas.value.length) {
      limite.value += paso
    }
  }

  watch(filas, () => {
    limite.value = inicial
    if (gridEl.value) gridEl.value.scrollTop = 0
  })

  return { gridEl, visibles, onScrollGrid }
}
