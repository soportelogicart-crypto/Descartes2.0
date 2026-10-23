<script setup lang="ts">
import { ref } from 'vue'
import { kindFromFile, type CompraArchivoKind } from '@/utils/compraDocumentoImport'

const emit = defineEmits<{
  file: [file: File, kind: CompraArchivoKind]
}>()

const props = withDefaults(
  defineProps<{
    disabled?: boolean
    compact?: boolean
  }>(),
  { disabled: false, compact: false }
)

const dragOver = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

function emitFile(file: File | null | undefined) {
  if (!file || props.disabled) return
  emit('file', file, kindFromFile(file))
}

function onDrop(e: DragEvent) {
  e.preventDefault()
  dragOver.value = false
  if (props.disabled) return
  const file = e.dataTransfer?.files?.[0]
  emitFile(file)
}

function onInputChange(e: Event) {
  const input = e.target as HTMLInputElement
  emitFile(input.files?.[0])
  input.value = ''
}

function abrirSelector() {
  if (!props.disabled) inputRef.value?.click()
}
</script>

<template>
  <div
    class="drop-zone"
    :class="{ 'drop-zone--over': dragOver, 'drop-zone--compact': compact, 'drop-zone--off': disabled }"
    role="button"
    tabindex="0"
    @click="abrirSelector"
    @keydown.enter.prevent="abrirSelector"
    @keydown.space.prevent="abrirSelector"
    @dragover.prevent="dragOver = !disabled"
    @dragleave.prevent="dragOver = false"
    @drop="onDrop"
  >
    <input
      ref="inputRef"
      type="file"
      class="sr-only"
      accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.csv,.txt,application/pdf,text/csv,image/*"
      @change="onInputChange"
    />
    <p class="drop-title">Arrastre el albarán del proveedor aquí</p>
    <p class="drop-hint">PDF digital o CSV · el proveedor ya debe estar indicado arriba</p>
  </div>
</template>

<style scoped>
.drop-zone {
  border: 2px dashed #94a3b8;
  border-radius: 10px;
  padding: 1rem 1.25rem;
  text-align: center;
  cursor: pointer;
  background: #f8fafc;
  transition:
    border-color 0.15s,
    background 0.15s;
}
.drop-zone--compact {
  padding: 0.65rem 1rem;
}
.drop-zone--over {
  border-color: #2563eb;
  background: #eff6ff;
}
.drop-zone--off {
  opacity: 0.55;
  cursor: not-allowed;
}
.drop-title {
  margin: 0;
  font-weight: 600;
  font-size: 0.95rem;
  color: #334155;
}
.drop-hint {
  margin: 0.35rem 0 0;
  font-size: 0.8rem;
  color: #64748b;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}
</style>
