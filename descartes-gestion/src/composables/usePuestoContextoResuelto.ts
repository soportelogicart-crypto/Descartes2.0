import { ref, watch, type MaybeRefOrGetter, toValue } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

export type PuestoContextoResuelto = {
  puesto: { codigo: string; descripcion: string }
  empresa: { codigo: string; nombre: string }
  almacen: { codigo: string; descripcion: string } | null
}

export type EquipoContextoInput = {
  empresaCodigo: string | null
  puestoCodigo: string | null
}

export function usePuestoContextoResuelto(equipo: MaybeRefOrGetter<EquipoContextoInput>) {
  const loading = ref(false)
  const error = ref<string | null>(null)
  const contexto = ref<PuestoContextoResuelto | null>(null)

  async function cargar() {
    const { empresaCodigo, puestoCodigo } = toValue(equipo)
    contexto.value = null
    error.value = null

    if (!empresaCodigo || !puestoCodigo) return

    loading.value = true
    try {
      const [puestoRes, tiendaRes] = await Promise.all([
        api.get(`/api/mantenimiento/puestos-trabajo/${encodeURIComponent(puestoCodigo)}`),
        api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(empresaCodigo)}`),
      ])

      const puesto = puestoRes.data
      const tienda = tiendaRes.data

      let almacen: PuestoContextoResuelto['almacen'] = null
      const almacenCodigo = tienda.almacenCodigo
      if (almacenCodigo != null && String(almacenCodigo).trim() !== '' && Number(almacenCodigo) > 0) {
        const { data: alm } = await api.get(
          `/api/mantenimiento/almacenes/${encodeURIComponent(String(almacenCodigo))}`
        )
        almacen = {
          codigo: String(alm.codigo ?? almacenCodigo),
          descripcion: String(alm.descripcion ?? ''),
        }
      }

      contexto.value = {
        puesto: {
          codigo: String(puesto.codigo ?? puestoCodigo).trim(),
          descripcion: String(puesto.descripcion ?? ''),
        },
        empresa: {
          codigo: String(tienda.codigo ?? empresaCodigo).trim(),
          nombre: String(tienda.nombre ?? tienda.nombreFiscal ?? ''),
        },
        almacen,
      }
    } catch (e: unknown) {
      const err = e as { response?: { status?: number; config?: { url?: string } } }
      if (err.response?.status === 404) {
        const url = err.response.config?.url ?? ''
        if (url.includes('/puestos-trabajo/')) {
          error.value = `No existe el puesto ${puestoCodigo}. Reconfigure este equipo.`
        } else if (url.includes('/tiendas/')) {
          error.value = `No existe la empresa ${empresaCodigo}. Reconfigure este equipo.`
        } else if (url.includes('/almacenes/')) {
          error.value = `No existe el almacen de la empresa ${empresaCodigo}.`
        } else {
          error.value = extractApiError(e, 'Registro no encontrado')
        }
      } else {
        error.value = extractApiError(e, 'No se pudo cargar el contexto del puesto')
      }
    } finally {
      loading.value = false
    }
  }

  watch(() => toValue(equipo), cargar, { immediate: true, deep: true })

  return { contexto, loading, error, recargar: cargar }
}
