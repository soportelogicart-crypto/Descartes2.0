<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  dimensionDesdePathAbcVentas,
  useRutaInstanciaKeepAlive,
} from '@/composables/useRutaInstanciaKeepAlive'
import { abcVentasListadoPorDimension } from '@/config/abc-ventas-dimensiones'
import { usePermisos } from '@/composables/usePermisos'
import { abcVentasModuloPermiso } from '@/config/listados-permisos'
import AbcVentasListadoHubView from '@/views/listados/AbcVentasListadoHubView.vue'
import AbcVentasView from '@/views/ventas/AbcVentasView.vue'

const route = useRoute()
const router = useRouter()
const { pathInstancia, esEstaInstanciaActiva } = useRutaInstanciaKeepAlive()
const { puede } = usePermisos()

const dimensionEfectiva = computed(() => {
  if (esEstaInstanciaActiva()) {
    return String(route.params.dimension ?? '').trim()
  }
  return dimensionDesdePathAbcVentas(pathInstancia)
})

const def = computed(() => {
  const p = dimensionEfectiva.value
  return p ? abcVentasListadoPorDimension(p) : undefined
})

const mostrarFormulario = computed(() => !!def.value)

watch(
  dimensionEfectiva,
  (p) => {
    if (!esEstaInstanciaActiva()) return
    if (p && (!abcVentasListadoPorDimension(p) || !puede(abcVentasModuloPermiso(p), 'ver'))) {
      void router.replace({ path: '/listados/abc-ventas' })
    }
  },
  { immediate: true },
)
</script>

<template>
  <AbcVentasListadoHubView v-show="!mostrarFormulario" />
  <AbcVentasView v-show="mostrarFormulario" />
</template>
