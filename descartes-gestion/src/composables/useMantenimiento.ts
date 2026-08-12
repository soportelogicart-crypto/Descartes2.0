import { type MaybeRefOrGetter, ref, toValue } from 'vue'
import { api } from '@/api/client'
import { leerGridPageSize } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/extractApiError'
export { extractApiError, type ApiErrorBody } from '@/composables/extractApiError'

export function useMantenimiento(entidad: MaybeRefOrGetter<string>) {
  const items = ref<Record<string, unknown>[]>([])
  const total = ref(0)
  const page = ref(1)
  const pageSize = ref(leerGridPageSize())
  const loading = ref(false)
  const error = ref<string | null>(null)

  function entidadActual(): string {
    return toValue(entidad)
  }

  async function listar(
    params: Record<string, string | number | boolean> = {},
    options?: { silent?: boolean }
  ) {
    const silent = options?.silent === true
    if (!silent) loading.value = true
    error.value = null
    try {
      const query = {
        page: page.value,
        pageSize: pageSize.value,
        ...params,
      }
      const { data } = await api.get(`/api/mantenimiento/${entidadActual()}`, { params: query })
      items.value = data.items ?? []
      total.value = data.total ?? 0
      page.value = data.page ?? page.value
      pageSize.value = data.pageSize ?? pageSize.value
    } catch (e: unknown) {
      error.value = extractApiError(e, 'Error al cargar listado')
    } finally {
      if (!silent) loading.value = false
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

  return { items, total, page, pageSize, loading, error, listar, obtener, crear, actualizar, eliminar }
}
