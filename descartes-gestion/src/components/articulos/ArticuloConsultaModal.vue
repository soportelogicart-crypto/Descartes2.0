<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  cerrar: []
}>()

const router = useRouter()
const q = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<{ codigo: string; descripcion: string; familia?: string }[]>([])

async function buscar() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/api/mantenimiento/articulos', {
      params: {
        page: 1,
        pageSize: 100,
        ...(q.value.trim() ? { q: q.value.trim() } : {}),
      },
    })
    items.value = (data.items ?? []).map((a: { codigo: string; descripcion: string; familia?: string }) => ({
      codigo: String(a.codigo ?? '').trim(),
      descripcion: String(a.descripcion ?? ''),
      familia: a.familia != null ? String(a.familia).trim() : '',
    }))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el listado')
    items.value = []
  } finally {
    loading.value = false
  }
}

function abrirEnNuevaPestana(codigo: string) {
  if (!codigo) return
  const href = router.resolve({
    path: '/mantenimiento/articulos',
    query: { codigo },
  }).href
  window.open(href, '_blank', 'noopener')
}

let debounce: ReturnType<typeof setTimeout> | null = null
watch(q, () => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => {
    void buscar()
  }, 350)
})

onMounted(() => {
  void buscar()
})
</script>

<template>
  <div v-if="open" class="overlay" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>Consulta de articulos</h3>
        <button type="button" class="close" @click="emit('cerrar')">×</button>
      </header>

      <div class="toolbar">
        <input
          v-model="q"
          type="search"
          placeholder="Buscar por codigo, descripcion..."
          autofocus
          @keydown.enter.prevent="buscar"
        />
        <button type="button" class="btn" :disabled="loading" @click="buscar">Buscar</button>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-else class="hint">Clic en un articulo para abrir su ficha en una pestaña nueva.</p>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Codigo</th>
              <th>Descripcion</th>
              <th>Familia</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="3">Cargando...</td>
            </tr>
            <tr v-else-if="!items.length">
              <td colspan="3">Sin resultados</td>
            </tr>
            <tr
              v-for="item in items"
              v-else
              :key="item.codigo"
              class="row"
              @click="abrirEnNuevaPestana(item.codigo)"
              @keydown.enter="abrirEnNuevaPestana(item.codigo)"
              tabindex="0"
            >
              <td>{{ item.codigo }}</td>
              <td>{{ item.descripcion }}</td>
              <td>{{ item.familia }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="modal-footer">
        <button type="button" class="btn" @click="emit('cerrar')">Cerrar</button>
      </footer>
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
  width: min(820px, 100%);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e2e8f0;
}

.modal-header h3 {
  margin: 0;
  font-size: 1rem;
}

.close {
  border: none;
  background: transparent;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
}

.toolbar {
  display: flex;
  gap: 0.5rem;
  padding: 0.75rem 1rem 0.25rem;
}

.toolbar input {
  flex: 1;
  padding: 0.4rem 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  font-size: 0.9rem;
}

.btn {
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  padding: 0.35rem 0.75rem;
  cursor: pointer;
  font-size: 0.85rem;
}

.hint,
.error {
  margin: 0;
  padding: 0.35rem 1rem 0.5rem;
  font-size: 0.8rem;
}

.hint {
  color: #64748b;
}

.error {
  color: #b91c1c;
}

.table-wrap {
  flex: 1;
  overflow: auto;
  padding: 0 1rem 0.5rem;
  min-height: 240px;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.4rem 0.45rem;
  text-align: left;
}

th {
  position: sticky;
  top: 0;
  background: #f8fafc;
  font-size: 0.75rem;
  color: #475569;
}

.row {
  cursor: pointer;
}

.row:hover,
.row:focus {
  background: #eff6ff;
  outline: none;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  padding: 0.75rem 1rem;
  border-top: 1px solid #e2e8f0;
}
</style>
