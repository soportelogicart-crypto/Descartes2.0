<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  dimensionDesdePathAbcCompras,
  useRutaInstanciaKeepAlive,
} from '@/composables/useRutaInstanciaKeepAlive'
import { abcComprasListadoPorDimension } from '@/config/abc-compras-dimensiones'
import { usePermisos } from '@/composables/usePermisos'
import { abcComprasModuloPermiso } from '@/config/listados-permisos'
import AbcComprasListadoHubView from '@/views/listados/AbcComprasListadoHubView.vue'
import AbcComprasView from '@/views/compras/AbcComprasView.vue'

const route = useRoute()
const router = useRouter()
const { pathInstancia, esEstaInstanciaActiva } = useRutaInstanciaKeepAlive()
const { puede } = usePermisos()

const dimensionEfectiva = computed(() => {
  if (esEstaInstanciaActiva()) {
    return String(route.params.dimension ?? '').trim()
  }
  return dimensionDesdePathAbcCompras(pathInstancia)
})

const def = computed(() => {
  const p = dimensionEfectiva.value
  return p ? abcComprasListadoPorDimension(p) : undefined
})

const mostrarFormulario = computed(() => !!def.value)

watch(
  dimensionEfectiva,
  (p) => {
    if (!esEstaInstanciaActiva()) return
    if (p && (!abcComprasListadoPorDimension(p) || !puede(abcComprasModuloPermiso(p), 'ver'))) {
      void router.replace({ path: '/listados/abc-compras' })
    }
  },
  { immediate: true },
)
</script>

<template>
  <AbcComprasListadoHubView v-show="!mostrarFormulario" />
  <AbcComprasView v-show="mostrarFormulario" />
</template>
