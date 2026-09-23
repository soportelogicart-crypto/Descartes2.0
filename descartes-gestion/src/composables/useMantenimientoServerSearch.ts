import { computed, onUnmounted, toValue, watch, type MaybeRefOrGetter, type Ref } from 'vue'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'

interface MantenimientoServerSearchOptions {
  filters: Ref<Record<string, ColumnFilter>>
  serverKeys: MaybeRefOrGetter<readonly string[]>
  reload: (q: string) => void | Promise<void>
  cancel?: () => void
  enabled?: MaybeRefOrGetter<boolean>
  debounceMs?: number
}

/**
 * Convierte el primer filtro de texto compatible con la API en `q`.
 * Los demás filtros siguen aplicándose localmente sobre las filas devueltas.
 */
export function useMantenimientoServerSearch(options: MantenimientoServerSearchOptions) {
  const query = computed(() => {
    for (const key of toValue(options.serverKeys)) {
      const filter = options.filters.value[key]
      if (!filter || filter.operador === 'sin_filtro') continue
      const value = String(filter.valor ?? '').trim()
      if (value) return value
    }
    return ''
  })

  let timer: ReturnType<typeof setTimeout> | null = null

  function clearPending() {
    if (timer === null) return
    clearTimeout(timer)
    timer = null
  }

  function reloadNow() {
    clearPending()
    if (options.enabled !== undefined && !toValue(options.enabled)) return
    void options.reload(query.value)
  }

  watch(query, () => {
    clearPending()
    if (options.enabled !== undefined && !toValue(options.enabled)) return
    timer = setTimeout(reloadNow, options.debounceMs ?? 350)
  })

  onUnmounted(() => {
    clearPending()
    options.cancel?.()
  })

  return {
    serverQuery: query,
    onServerSearch: reloadNow,
    cancelPendingServerSearch: clearPending,
  }
}
