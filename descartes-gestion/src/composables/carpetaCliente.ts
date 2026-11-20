import { computed, ref } from 'vue'
import { getDescartesBridge } from '@/bridge/electron'
import { useCarpetaCompartidaStore } from '@/stores/carpetaCompartida'
import { CLIENTE_RAPIDO_TPV } from '@/types/tpv'

/**
 * Carpeta personal del cliente dentro de la carpeta compartida (solo Electron).
 * Se usa desde la ficha de cliente, el TPV y la cabecera de la venta.
 */
export function useCarpetaCliente() {
  const carpetaStore = useCarpetaCompartidaStore()

  const abierta = ref(false)
  const ruta = ref('')
  const titulo = ref('Carpeta del cliente')
  const error = ref('')

  const disponible = computed(() => Boolean(getDescartesBridge()?.carpetaCompartida?.cliente) && Boolean(carpetaStore.ruta))

  /** Venta rápida (ZZZZZZZZZ) y vacío no tienen carpeta propia. */
  function tieneCarpeta(codigo: string | null | undefined): boolean {
    const c = String(codigo ?? '').trim()
    return c !== '' && c.toUpperCase() !== CLIENTE_RAPIDO_TPV
  }

  async function abrir(codigo: string, nombre = '') {
    error.value = ''
    const carpeta = getDescartesBridge()?.carpetaCompartida
    if (!carpeta?.cliente) {
      error.value = 'La carpeta del cliente solo está disponible en Descartes Electron.'
      return
    }
    if (!carpetaStore.ruta) {
      error.value = 'Configure primero la carpeta compartida.'
      return
    }
    const res = await carpeta.cliente(carpetaStore.ruta, codigo.trim())
    if (!res.ok || !res.ruta) {
      error.value = res.message ?? 'No se pudo abrir la carpeta del cliente'
      return
    }
    ruta.value = res.ruta
    const n = nombre.trim()
    titulo.value = n ? `Carpeta de ${n}` : `Carpeta de ${codigo.trim()}`
    abierta.value = true
  }

  function cerrar() {
    abierta.value = false
  }

  return { disponible, tieneCarpeta, abierta, ruta, titulo, error, abrir, cerrar }
}
