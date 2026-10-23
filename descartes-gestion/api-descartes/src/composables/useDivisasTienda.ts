import { ref, watch } from 'vue'
import { api } from '@/api/client'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

export type DivisaOpcion = { value: string; label: string }

/**
 * Divisas configuradas en Mantenimiento > Tiendas > Facturación.
 * Legacy ofrece la divisa principal y la alternativa de la tienda activa.
 */
export function useDivisasTienda() {
  const puesto = usePuestoContextoStore()
  const opciones = ref<DivisaOpcion[]>([])

  async function cargar(codigo = puesto.empresaCodigo?.trim() ?? '') {
    if (!codigo) {
      opciones.value = []
      return
    }
    try {
      const { data } = await api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(codigo)}`)
      const valores = [
        String(data?.divisa ?? '').trim().toUpperCase(),
        String(data?.divisaAlt ?? '').trim().toUpperCase(),
      ].filter(Boolean)
      opciones.value = [...new Set(valores)].map((value) => ({ value, label: value }))
    } catch {
      opciones.value = []
    }
  }

  watch(
    () => puesto.empresaCodigo,
    (codigo) => void cargar(codigo?.trim() ?? ''),
    { immediate: true }
  )

  return { divisasTienda: opciones, cargarDivisasTienda: cargar }
}
