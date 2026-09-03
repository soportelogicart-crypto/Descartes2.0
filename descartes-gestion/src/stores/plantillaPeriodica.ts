import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type PlantillaPeriodicaModo = 'inactivo' | 'alta' | 'consulta'

/** Contexto al trabajar plantillas periódicas desde Mantenimiento ↔ Ventas. */
export const usePlantillaPeriodicaStore = defineStore('plantillaPeriodica', () => {
  const modo = ref<PlantillaPeriodicaModo>('inactivo')

  const activo = computed(() => modo.value !== 'inactivo')
  const esAlta = computed(() => modo.value === 'alta')
  const esConsulta = computed(() => modo.value === 'consulta')

  function iniciarAlta() {
    modo.value = 'alta'
  }

  function iniciarConsulta() {
    modo.value = 'consulta'
  }

  /** @deprecated Use iniciarAlta */
  function iniciar() {
    iniciarAlta()
  }

  function cancelar() {
    modo.value = 'inactivo'
  }

  return { modo, activo, esAlta, esConsulta, iniciar, iniciarAlta, iniciarConsulta, cancelar }
})
