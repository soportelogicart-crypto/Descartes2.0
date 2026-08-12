<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    message: string
    confirmLabel?: string
    cancelLabel?: string
    danger?: boolean
    /** Solo boton Aceptar (aviso informativo). */
    hideCancel?: boolean
  }>(),
  {
    title: 'Confirmar',
    confirmLabel: 'Eliminar',
    cancelLabel: 'Cancelar',
    danger: true,
    hideCancel: false,
  }
)

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

/** Evita que el click que abrio el dialogo cierre el overlay al soltar el raton. */
const ignoreOverlayClick = ref(false)

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) {
      ignoreOverlayClick.value = false
      return
    }
    ignoreOverlayClick.value = true
    await nextTick()
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        ignoreOverlayClick.value = false
      })
    })
  }
)

function onOverlayClick() {
  if (ignoreOverlayClick.value) return
  emit('cancel')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-show="open"
      class="overlay"
      role="dialog"
      aria-modal="true"
      @click.self="onOverlayClick"
    >
      <div class="modal">
        <header class="modal-header">
          <span class="header-icon" :class="{ danger }">
            <ToolIcon name="aviso" />
          </span>
          <h3>{{ title }}</h3>
        </header>
        <p class="message">{{ message }}</p>
        <footer class="modal-footer">
          <button v-if="!hideCancel" type="button" class="btn-cancel" @click="emit('cancel')">
            {{ cancelLabel }}
          </button>
          <button type="button" class="btn-confirm" :class="{ danger }" @click="emit('confirm')">
            {{ confirmLabel }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  z-index: 5000;
  padding: 1rem;
}

.modal {
  width: min(420px, 100%);
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
}

.modal-header {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.85rem 1rem 0;
}

.header-icon {
  display: inline-flex;
  color: #d97706;
}

.header-icon.danger {
  color: #dc2626;
}

.modal-header h3 {
  margin: 0;
  font-size: 1rem;
}

.message {
  margin: 0.75rem 1rem 1rem;
  color: #334155;
  line-height: 1.45;
  white-space: pre-line;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0 1rem 1rem;
}

.btn-cancel,
.btn-confirm {
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
  background: #fff;
  cursor: pointer;
  font-size: 0.85rem;
}

.btn-confirm:not(.danger) {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.btn-confirm.danger {
  background: #dc2626;
  border-color: #b91c1c;
  color: #fff;
}

.btn-confirm.danger:hover {
  background: #b91c1c;
}
</style>
