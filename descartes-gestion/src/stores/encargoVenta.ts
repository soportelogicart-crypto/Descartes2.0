import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Aviso del encargo que sale de guardar una venta con líneas "con sobre".
 * Lo rellena `crearVenta`/`actualizarVenta`; lo lee el banner del albarán
 * (relee el encargo y avisa de las etiquetas pendientes de imprimir).
 */
export const useEncargoVentaStore = defineStore('encargoVenta', () => {
  const tick = ref(0)
  const empresa = ref('')
  const tipo = ref('')
  const albaran = ref(0)
  const aviso = ref<string | null>(null)

  function registrar(datos: {
    empresa: string
    tipo: string
    albaran: number
    aviso?: string | null
  }) {
    empresa.value = datos.empresa
    tipo.value = datos.tipo
    albaran.value = datos.albaran
    aviso.value = datos.aviso ?? null
    tick.value++
  }

  /** El banner ya mostró el aviso: no repetirlo en recargas posteriores. */
  function consumir() {
    aviso.value = null
    albaran.value = 0
  }

  return { tick, empresa, tipo, albaran, aviso, registrar, consumir }
})
