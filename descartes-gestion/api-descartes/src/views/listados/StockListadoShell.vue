<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  agruparPorDesdePathStock,
  useRutaInstanciaKeepAlive,
} from '@/composables/useRutaInstanciaKeepAlive'
import { stockListadoPorAgrupar } from '@/config/stock-listado-config'
import { usePermisos } from '@/composables/usePermisos'
import { stockListadoModuloPermiso } from '@/config/listados-permisos'
import StockListadoHubView from '@/views/listados/StockListadoHubView.vue'
import StockListadoView from '@/views/listados/StockListadoView.vue'

const route = useRoute()
const router = useRouter()
const { pathInstancia, esEstaInstanciaActiva } = useRutaInstanciaKeepAlive()
const { puede } = usePermisos()

const agruparEfectivo = computed(() => {
  if (esEstaInstanciaActiva()) {
    return String(route.params.agruparPor ?? '').trim()
  }
  return agruparPorDesdePathStock(pathInstancia)
})

const def = computed(() => {
  const p = agruparEfectivo.value
  return p ? stockListadoPorAgrupar(p) : undefined
})

const mostrarFormulario = computed(() => !!def.value)

watch(
  agruparEfectivo,
  (p) => {
    if (!esEstaInstanciaActiva()) return
    if (p && (!stockListadoPorAgrupar(p) || !puede(stockListadoModuloPermiso(p), 'ver'))) {
      void router.replace({ path: '/listados/stock' })
    }
  },
  { immediate: true },
)
</script>

<template>
  <!-- v-show: al cambiar de pestaña useRoute() ya no es stock; v-if desmontaba el formulario. -->
  <StockListadoHubView v-show="!mostrarFormulario" />
  <StockListadoView v-show="mostrarFormulario" />
</template>
