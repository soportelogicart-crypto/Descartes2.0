<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { parseInteresesComerciales, serializeInteresesComerciales } from '@/config/proveedores-tabs'

const props = defineProps<{
  open: boolean
  modelValue: string
  readonly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  cerrar: []
}>()

const intereses = ref<{ codigo: string; descripcion: string; checked: boolean }[]>([])
const loading = ref(false)

watch(
  () => props.open,
  async (abierto) => {
    if (abierto) await cargar()
  }
)

onMounted(async () => {
  if (props.open) await cargar()
})

async function cargar() {
  loading.value = true
  try {
    const { data } = await api.get('/api/mantenimiento/intereses-comerciales', { params: { pageSize: 500 } })
    const seleccionados = new Set(parseInteresesComerciales(props.modelValue))
    intereses.value = (data.items ?? []).map((item: { codigo: string; descripcion: string }) => {
      const codigo = String(item.codigo ?? '').trim()
      return {
        codigo,
        descripcion: String(item.descripcion ?? ''),
        checked: seleccionados.has(codigo),
      }
    })
  } finally {
    loading.value = false
  }
}

function toggle(index: number) {
  if (props.readonly) return
  intereses.value = intereses.value.map((item, i) =>
    i === index ? { ...item, checked: !item.checked } : item
  )
}

function aceptar() {
  if (!props.readonly) {
    const codes = intereses.value.filter((i) => i.checked).map((i) => i.codigo)
    emit('update:modelValue', serializeInteresesComerciales(codes))
  }
  emit('cerrar')
}
</script>

<template>
  <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>Intereses</h3>
        <button type="button" class="btn-salir" @click="aceptar">Salir</button>
      </header>

      <p v-if="loading" class="loading">Cargando...</p>
      <table v-else class="grid">
        <thead>
          <tr>
            <th>Codigo</th>
            <th>Descripcion</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, index) in intereses" :key="item.codigo" @click="toggle(index)">
            <td>{{ item.codigo }}</td>
            <td>{{ item.descripcion }}</td>
            <td class="col-check">
              <input type="checkbox" :checked="item.checked" :disabled="readonly" @click.stop="toggle(index)" />
            </td>
          </tr>
          <tr v-if="intereses.length === 0">
            <td colspan="3">Sin intereses definidos</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  z-index: 60;
  padding: 1rem;
}

.modal {
  width: min(420px, 100%);
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
  overflow: hidden;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}

.modal-header h3 {
  margin: 0;
  font-size: 0.95rem;
}

.btn-salir {
  padding: 0.3rem 0.65rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.8rem;
}

.loading {
  padding: 1rem;
  margin: 0;
}

.grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

.grid th,
.grid td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.35rem 0.55rem;
  text-align: left;
}

.grid th {
  background: #f1f5f9;
}

.grid tbody tr {
  cursor: pointer;
}

.grid tbody tr:hover {
  background: #eff6ff;
}

.col-check {
  width: 2.5rem;
  text-align: center;
}

input[type='checkbox'] {
  width: 1rem;
  height: 1rem;
}
</style>

