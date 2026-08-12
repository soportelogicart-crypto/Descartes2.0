<script setup lang="ts">
import { computed } from 'vue'
import { guardarGridPageSize } from '@/composables/useGridPageSize'

const props = withDefaults(
  defineProps<{
    page: number
    pageSize: number
    total: number
    loading?: boolean
    pageSizeOptions?: number[]
  }>(),
  {
    loading: false,
    pageSizeOptions: () => [25, 50, 100, 200],
  }
)

const emit = defineEmits<{
  'update:page': [page: number]
  'update:pageSize': [pageSize: number]
}>()

const totalPages = computed(() => Math.max(1, Math.ceil(props.total / Math.max(1, props.pageSize))))

const desde = computed(() => {
  if (props.total <= 0) return 0
  return (props.page - 1) * props.pageSize + 1
})

const hasta = computed(() => Math.min(props.total, props.page * props.pageSize))

const puedeAnterior = computed(() => props.page > 1 && !props.loading)
const puedeSiguiente = computed(() => props.page < totalPages.value && !props.loading)

function ir(p: number) {
  const next = Math.min(totalPages.value, Math.max(1, p))
  if (next !== props.page) emit('update:page', next)
}

function onPageSize(e: Event) {
  const v = Number((e.target as HTMLSelectElement).value) || props.pageSize
  guardarGridPageSize(v)
  emit('update:pageSize', v)
}
</script>

<template>
  <div class="pager" role="navigation" aria-label="Paginacion">
    <span class="info">
      <template v-if="total > 0"> {{ desde }}–{{ hasta }} de {{ total }} </template>
      <template v-else>0 registros</template>
    </span>
    <label class="size">
      Por pagina
      <select :value="pageSize" :disabled="loading" @change="onPageSize">
        <option v-for="n in pageSizeOptions" :key="n" :value="n">{{ n }}</option>
      </select>
    </label>
    <div class="nav">
      <button type="button" :disabled="!puedeAnterior" @click="ir(1)">«</button>
      <button type="button" :disabled="!puedeAnterior" @click="ir(page - 1)">Anterior</button>
      <span class="page">Pag. {{ page }} / {{ totalPages }}</span>
      <button type="button" :disabled="!puedeSiguiente" @click="ir(page + 1)">Siguiente</button>
      <button type="button" :disabled="!puedeSiguiente" @click="ir(totalPages)">»</button>
    </div>
  </div>
</template>

<style scoped>
.pager {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem 1rem;
  margin-top: 0.65rem;
  font-size: 0.82rem;
  color: #475569;
}
.info {
  min-width: 8rem;
}
.size {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.size select {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
.nav {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-left: auto;
}
.nav button {
  padding: 0.25rem 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #f8fafc;
  cursor: pointer;
}
.nav button:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.page {
  padding: 0 0.35rem;
  white-space: nowrap;
}
</style>
