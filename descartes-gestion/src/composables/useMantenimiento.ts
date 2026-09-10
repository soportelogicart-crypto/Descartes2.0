import { type MaybeRefOrGetter, ref, toValue } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'
export { extractApiError, type ApiErrorBody } from '@/composables/extractApiError'

/** Bloques al traer el listado completo (la API admite hasta 5000). */
const BLOQUE_CARGA = 5000
/** Tope de seguridad si el filtro deja decenas de miles de filas. */
const MAX_FILAS = 50000

export async function listarEntidadCompleta(
  entidad: string,
  params: Record<string, string | number | boolean> = {}
): Promise<{ items: Record<string, unknown>[]; total: number }> {
  const extra = { ...params }
  delete extra.page
  delete extra.pageSize
  const acumulado: Record<string, unknown>[] = []
  let pagina = 1
  let total = 0
  for (;;) {
    const { data } = await api.get(`/api/mantenimiento/${entidad}`, {
      params: { ...extra, page: pagina, pageSize: BLOQUE_CARGA },
    })
    const lote = (data.items ?? []) as Record<string, unknown>[]
    acumulado.push(...lote)
    total = Number(data.total ?? acumulado.length)
    if (lote.length === 0 || acumulado.length >= total || acumulado.length >= MAX_FILAS) {
      break
    }
    pagina += 1
  }
  return { items: acumulado, total }
}

export function useMantenimiento(entidad: MaybeRefOrGetter<string>) {
  const items = ref<Record<string, unknown>[]>([])
  const total = ref(0)
  const page = ref(1)
  const pageSize = ref(BLOQUE_CARGA)
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
      const { items: all, total: t } = await listarEntidadCompleta(entidadActual(), params)
      items.value = all
      total.value = t
      page.value = 1
      pageSize.value = all.length || BLOQUE_CARGA
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
