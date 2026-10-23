import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type ComputedRef, type Ref } from 'vue'

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
    if (!el || limite.value >= filas.value.length) return
    const scrolleaDentro = el.scrollHeight > el.clientHeight + 4
    const cercaDentro = scrolleaDentro && el.scrollTop + el.clientHeight >= el.scrollHeight - 250
    const cercaVentana = el.getBoundingClientRect().bottom <= window.innerHeight + 250
    if (!cercaDentro && !cercaVentana) return
    limite.value += paso
  }

  watch(filas, () => {
    limite.value = inicial
    if (gridEl.value) gridEl.value.scrollTop = 0
  })

  watch(visibles, () => {
    void nextTick(onScrollGrid)
  })

  onMounted(() => {
    window.addEventListener('scroll', onScrollGrid, true)
    void nextTick(onScrollGrid)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScrollGrid, true)
  })

  return { gridEl, visibles, onScrollGrid }
}
