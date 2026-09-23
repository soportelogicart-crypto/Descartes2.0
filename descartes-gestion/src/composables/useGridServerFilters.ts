import { onUnmounted, watch } from 'vue'

/**
 * Relanza la consulta del listado cuando cambian los filtros de columna que la
 * API sabe resolver, para que la búsqueda no se limite al bloque ya cargado.
 * Los demás filtros de columna siguen aplicándose en local sobre lo devuelto.
 */
export function useGridServerFilters(
  clave: () => string,
  recargar: () => void | Promise<void>,
  debounceMs = 400
) {
  let timer: ReturnType<typeof setTimeout> | null = null

  function cancelarPendiente() {
    if (timer === null) return
    clearTimeout(timer)
    timer = null
  }

  function recargarAhora() {
    cancelarPendiente()
    void recargar()
  }

  watch(clave, () => {
    cancelarPendiente()
    timer = setTimeout(recargarAhora, debounceMs)
  })

  onUnmounted(cancelarPendiente)

  return { recargarAhora, cancelarPendiente }
}
