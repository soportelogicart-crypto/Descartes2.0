<script setup lang="ts">
import { onUnmounted, watch } from 'vue'

const props = defineProps<{
  url: string | null
  mime: string | null
  nombre: string | null
}>()

const emit = defineEmits<{ close: [] }>()

watch(
  () => props.url,
  (_n, old) => {
    if (old?.startsWith('blob:')) URL.revokeObjectURL(old)
  }
)

onUnmounted(() => {
  if (props.url?.startsWith('blob:')) URL.revokeObjectURL(props.url)
})

const esPdf = () => (props.mime || '').includes('pdf') || (props.nombre || '').toLowerCase().endsWith('.pdf')
</script>

<template>
  <aside v-if="url" class="visor-doc">
    <header class="visor-head">
      <span class="visor-title">{{ nombre || 'Documento' }}</span>
      <button type="button" class="visor-close" title="Cerrar visor" @click="emit('close')">×</button>
    </header>
    <div class="visor-body">
      <iframe v-if="esPdf()" :src="url" title="Vista previa PDF" />
      <img v-else :src="url" :alt="nombre || 'Documento proveedor'" />
    </div>
  </aside>
</template>

<style scoped>
.visor-doc {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
  display: flex;
  flex-direction: column;
  min-height: 280px;
  max-height: min(70vh, 520px);
  overflow: hidden;
}
.visor-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.4rem 0.65rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}
.visor-title {
  font-size: 0.85rem;
  font-weight: 600;
  color: #475569;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.visor-close {
  border: none;
  background: transparent;
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
  color: #64748b;
  padding: 0 0.25rem;
}
.visor-body {
  flex: 1;
  min-height: 0;
  background: #f1f5f9;
}
.visor-body iframe,
.visor-body img {
  width: 100%;
  height: 100%;
  min-height: 240px;
  border: 0;
  object-fit: contain;
}
</style>
