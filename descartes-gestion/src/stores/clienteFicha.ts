import { defineStore } from 'pinia'
import { ref } from 'vue'
import { clienteTabs } from '@/config/clientes-tabs'

/**
 * Borrador de la ficha de cliente. Vive fuera de EntidadView/KeepAlive:
 * al cambiar de pestaña la vista se destruía y el alta volvía al grid.
 */
export const useClienteFichaStore = defineStore('clienteFicha', () => {
  const vista = ref<'grid' | 'ficha'>('grid')
  const ficha = ref<Record<string, unknown>>({})
  const esNuevo = ref(false)
  const modoEdicion = ref(false)
  const tabActiva = ref(clienteTabs[0].id)
  const codigoAutomatico = ref(false)
  const indiceFicha = ref(-1)
  const mensaje = ref<string | null>(null)
  const camposInvalidos = ref<string[]>([])

  function limpiarFicha() {
    vista.value = 'grid'
    modoEdicion.value = false
    esNuevo.value = false
    codigoAutomatico.value = false
    ficha.value = {}
    indiceFicha.value = -1
    tabActiva.value = clienteTabs[0].id
    camposInvalidos.value = []
  }

  return {
    vista,
    ficha,
    esNuevo,
    modoEdicion,
    tabActiva,
    codigoAutomatico,
    indiceFicha,
    mensaje,
    camposInvalidos,
    limpiarFicha,
  }
})
