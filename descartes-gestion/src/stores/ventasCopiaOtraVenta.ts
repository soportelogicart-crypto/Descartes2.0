import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { VentaDetalle, VentaLinea } from '@/types/ventas'

export type VentaCopiaOtraVentaPendiente = {
  ficha: VentaDetalle
  lineas: VentaLinea[]
  /** Tras copiar, abrir paso cliente para elegir otro. */
  elegirCliente: boolean
}

/** Borrador al pulsar «Otra venta» en un documento recuperado → ruta ventas-nuevo. */
export const useVentasCopiaOtraVentaStore = defineStore('ventasCopiaOtraVenta', () => {
  const pendiente = ref<VentaCopiaOtraVentaPendiente | null>(null)

  function preparar(payload: VentaCopiaOtraVentaPendiente) {
    pendiente.value = {
      ficha: structuredClone(payload.ficha),
      lineas: structuredClone(payload.lineas),
      elegirCliente: payload.elegirCliente,
    }
  }

  /** Devuelve la copia pendiente y la elimina (un solo uso). */
  function consumir(): VentaCopiaOtraVentaPendiente | null {
    const p = pendiente.value
    pendiente.value = null
    return p
  }

  function cancelar() {
    pendiente.value = null
  }

  return { pendiente, preparar, consumir, cancelar }
})
