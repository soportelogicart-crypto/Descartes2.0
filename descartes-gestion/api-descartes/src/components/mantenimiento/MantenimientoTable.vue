<script setup lang="ts">
import type { CampoEntidad } from '@/config/entidades'

defineProps<{
  items: Record<string, unknown>[]
  campos: CampoEntidad[]
  loading?: boolean
  puedeEditar?: boolean
  puedeEliminar?: boolean
}>()

defineEmits<{
  editar: [codigo: string]
  eliminar: [codigo: string]
}>()
</script>

<template>
  <div class="table-wrap">
    <p v-if="loading">Cargando...</p>
    <table v-else>
      <thead>
        <tr>
          <th v-for="campo in campos.slice(0, 4)" :key="campo.key">{{ campo.label }}</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="String(item.codigo)">
          <td v-for="campo in campos.slice(0, 4)" :key="campo.key">
            {{ item[campo.key] }}
          </td>
          <td class="actions">
            <button v-if="puedeEditar !== false" type="button" @click="$emit('editar', String(item.codigo))">
              Editar
            </button>
            <button
              v-if="puedeEliminar !== false"
              type="button"
              class="danger"
              @click="$emit('eliminar', String(item.codigo))"
            >
              Baja
            </button>
          </td>
        </tr>
        <tr v-if="items.length === 0">
          <td :colspan="campos.length + 1">Sin registros</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
}

th,
td {
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid #e5e7eb;
  text-align: left;
}

.actions {
  display: flex;
  gap: 0.5rem;
}

button {
  border: 1px solid #d1d5db;
  background: #fff;
  border-radius: 6px;
  padding: 0.25rem 0.5rem;
}

button.danger {
  color: #b91c1c;
  border-color: #fecaca;
}
</style>
