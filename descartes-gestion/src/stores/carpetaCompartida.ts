import { defineStore } from 'pinia'
import { ref } from 'vue'
import { obtenerRutaCarpetaCompartida } from '@/api/carpetaCompartida'

/** Ruta UNC guardada para la instalación. Vacía = sin carpeta, y el acceso de la cabecera no se muestra. */
export const useCarpetaCompartidaStore = defineStore('carpetaCompartida', () => {
  const ruta = ref('')

  async function cargar() {
    try {
      ruta.value = (await obtenerRutaCarpetaCompartida()).trim()
    } catch {
      ruta.value = ''
    }
  }

  function fijar(nueva: string) {
    ruta.value = nueva.trim()
  }

  return { ruta, cargar, fijar }
})
