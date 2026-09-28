<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue'
import { useRoute } from 'vue-router'
import { getEntidadConfig } from '@/config/entidades'
import MantenimientoCrud from '@/components/mantenimiento/MantenimientoCrud.vue'

const TiendasView = defineAsyncComponent(() => import('@/views/mantenimiento/TiendasView.vue'))
const AlmacenesView = defineAsyncComponent(() => import('@/views/mantenimiento/AlmacenesView.vue'))
const ArticulosView = defineAsyncComponent(() => import('@/views/mantenimiento/ArticulosView.vue'))
const ClientesView = defineAsyncComponent(() => import('@/views/mantenimiento/ClientesView.vue'))
const RolesView = defineAsyncComponent(() => import('@/views/mantenimiento/RolesView.vue'))
const UsuariosView = defineAsyncComponent(() => import('@/views/mantenimiento/UsuariosView.vue'))
const TrabajadoresView = defineAsyncComponent(() => import('@/views/mantenimiento/TrabajadoresView.vue'))

const props = defineProps<{ entidadFija?: string }>()
const route = useRoute()
// KeepAlive cachea esta vista por fullPath. useRoute() es global: si `entidad`
// es computed, al cambiar de pestaña el v-if destruye ClientesView y se pierde
// el alta (vuelve al grid). Cada instancia corresponde a una entidad.
const entidad = String(props.entidadFija ?? route.params.entidad ?? '')
const config = computed(() => getEntidadConfig(entidad))
</script>

<template>
  <TiendasView v-if="entidad === 'tiendas'" />
  <AlmacenesView v-else-if="entidad === 'almacenes'" />
  <ArticulosView v-else-if="entidad === 'articulos'" />
  <ClientesView v-else-if="entidad === 'clientes'" />
  <RolesView v-else-if="entidad === 'roles'" />
  <UsuariosView v-else-if="entidad === 'usuarios'" />
  <TrabajadoresView v-else-if="entidad === 'trabajadores'" />
  <div v-else-if="config">
    <MantenimientoCrud :key="entidad" :entidad="entidad" :config="config" />
  </div>
  <p v-else>Entidad no configurada: {{ entidad }}</p>
</template>
