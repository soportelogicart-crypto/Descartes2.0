import { type MaybeRefOrGetter, ref, toValue } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
export { extractApiError, type ApiErrorBody } from '@/composables/extractApiError'

export async function listarEntidadCompleta(
  entidad: string,
  params: Record<string, string | number | boolean> = {},
  signal?: AbortSignal
): Promise<{ items: Record<string, unknown>[]; total: number }> {
  const extra = { ...params }
  delete extra.page
  delete extra.pageSize
  if (signal?.aborted) {
    throw new DOMException('canceled', 'AbortError')
  }
  const { data } = await api.get(`/api/mantenimiento/${entidad}`, {
    params: { ...extra, page: 1, pageSize: GRID_LIMITE_INICIAL },
    signal,
  })
  const items = (data.items ?? []) as Record<string, unknown>[]
  return { items, total: Number(data.total ?? items.length) }
}

function esCancelado(e: unknown): boolean {
  const err = e as { code?: string; name?: string }
  return err.code === 'ERR_CANCELED' || err.name === 'CanceledError' || err.name === 'AbortError'
}

export function useMantenimiento(entidad: MaybeRefOrGetter<string>) {
  const items = ref<Record<string, unknown>[]>([])
  const total = ref(0)
  const page = ref(1)
  const pageSize = ref(GRID_LIMITE_INICIAL)
  const loading = ref(false)
  const error = ref<string | null>(null)
  let abortListar: AbortController | null = null

  function entidadActual(): string {
    return toValue(entidad)
  }

  function cancelarListado() {
    abortListar?.abort()
    abortListar = null
    loading.value = false
  }

  async function listar(
    params: Record<string, string | number | boolean> = {},
    options?: { silent?: boolean; signal?: AbortSignal }
  ) {
    const silent = options?.silent === true
    abortListar?.abort()
    abortListar = new AbortController()
    const signal = options?.signal ?? abortListar.signal
    if (!silent) loading.value = true
    error.value = null
    try {
      const { items: all, total: t } = await listarEntidadCompleta(entidadActual(), params, signal)
      if (signal.aborted) return
      items.value = all
      total.value = t
      page.value = 1
      pageSize.value = GRID_LIMITE_INICIAL
    } catch (e: unknown) {
      if (esCancelado(e)) return
      error.value = extractApiError(e, 'Error al cargar listado')
    } finally {
      if (!silent && !signal.aborted) loading.value = false
    }
  }

  async function obtener(codigo: string) {
    const { data } = await api.get(`/api/mantenimiento/${entidadActual()}/${encodeURIComponent(codigo)}`)
    return data
  }

  async function crear(payload: Record<string, unknown>) {
    const { data } = await api.post(`/api/mantenimiento/${entidadActual()}`, payload)
    return data
  }

  async function actualizar(codigo: string, payload: Record<string, unknown>) {
    const { data } = await api.put(
      `/api/mantenimiento/${entidadActual()}/${encodeURIComponent(codigo)}`,
      payload
    )
    return data
  }

  async function eliminar(codigo: string) {
    await api.delete(`/api/mantenimiento/${entidadActual()}/${encodeURIComponent(codigo)}`)
  }

  return {
    items,
    total,
    page,
    pageSize,
    loading,
    error,
    listar,
    cancelarListado,
    obtener,
    crear,
    actualizar,
    eliminar,
  }
}
