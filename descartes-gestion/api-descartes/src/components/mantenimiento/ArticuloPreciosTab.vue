<script setup lang="ts">
import { ref, watch } from 'vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

export interface PrecioEscalonado {
  desdeCantidad: number
  precio: number
}

const props = defineProps<{
  modelValue: PrecioEscalonado[]
  readonly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: PrecioEscalonado[]]
}>()

const filas = ref<PrecioEscalonado[]>([...props.modelValue])

watch(
  () => props.modelValue,
  (value) => {
    filas.value = [...value]
  },
  { deep: true }
)

function emitir() {
  emit('update:modelValue', filas.value.map((f) => ({
    desdeCantidad: Number(f.desdeCantidad),
    precio: Number(f.precio),
  })))
}

function anadir() {
  filas.value.push({ desdeCantidad: 1, precio: 0 })
  emitir()
}

function quitar(index: number) {
  filas.value.splice(index, 1)
  emitir()
}

function onChange() {
  emitir()
}
</script>

<template>
  <section class="precios-tab">
    <header>
      <h3>Precios escalonados</h3>
      <button v-if="!readonly" type="button" @click="anadir">Anadir tramo</button>
    </header>
    <p class="hint">Precio base en el formulario principal (Precio venta). Tramos adicionales por cantidad minima.</p>
    <table v-if="filas.length">
      <thead>
        <tr>
          <th>Desde cantidad</th>
          <th>Precio</th>
          <th v-if="!readonly" />
        </tr>
      </thead>
      <tbody>
        <tr v-for="(fila, index) in filas" :key="index">
          <td>
            <DecimalInput
              :model-value="fila.desdeCantidad"
              :empty-as-null="false"
              :readonly="readonly"
              @update:model-value="(v) => { fila.desdeCantidad = v ?? 0; onChange() }"
            />
          </td>
          <td>
            <DecimalInput
              :model-value="fila.precio"
              :empty-as-null="false"
              :readonly="readonly"
              @update:model-value="(v) => { fila.precio = v ?? 0; onChange() }"
            />
          </td>
          <td v-if="!readonly">
            <button type="button" @click="quitar(index)">Quitar</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="empty">Sin precios escalonados.</p>
  </section>
</template>

<style scoped>
.precios-tab {
  margin-top: 1rem;
  background: #fff;
  border-radius: 8px;
  padding: 1rem;
}

header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
}

h3 {
  margin: 0;
  font-size: 1rem;
}

.hint {
  color: #6b7280;
  font-size: 0.875rem;
  margin: 0 0 0.75rem;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  text-align: left;
  padding: 0.5rem;
  border-bottom: 1px solid #e5e7eb;
}

input {
  width: 100%;
  padding: 0.4rem 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
}

.empty {
  color: #6b7280;
}
</style>
