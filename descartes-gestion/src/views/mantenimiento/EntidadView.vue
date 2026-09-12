<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { getEntidadConfig } from '@/config/entidades'
import MantenimientoCrud from '@/components/mantenimiento/MantenimientoCrud.vue'
import TiendasView from '@/views/mantenimiento/TiendasView.vue'
import AlmacenesView from '@/views/mantenimiento/AlmacenesView.vue'
import ArticulosView from '@/views/mantenimiento/ArticulosView.vue'
import ClientesView from '@/views/mantenimiento/ClientesView.vue'
import RolesView from '@/views/mantenimiento/RolesView.vue'
import UsuariosView from '@/views/mantenimiento/UsuariosView.vue'
import TrabajadoresView from '@/views/mantenimiento/TrabajadoresView.vue'

const route = useRoute()
// KeepAlive cachea esta vista por fullPath. useRoute() es global: si `entidad`
// es computed, al cambiar de pestaña el v-if destruye ClientesView y se pierde
// el alta (vuelve al grid). Cada instancia corresponde a una entidad.
const entidad = String(route.params.entidad ?? '')
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
