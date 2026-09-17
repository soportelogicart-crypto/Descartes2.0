<script setup lang="ts">
import ToolIcon from '@/components/common/ToolIcon.vue'

defineProps<{
  open: boolean
  titulo: string
  filasCount: number
}>()

const emit = defineEmits<{
  excel: []
  imprimir: []
  cancel: []
}>()
</script>

<template>
  <Teleport to="body">
    <div v-show="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cancel')">
      <div class="modal">
        <header class="modal-header">
          <span class="header-icon">
            <ToolIcon name="listado" />
          </span>
          <h3>Listado — {{ titulo }}</h3>
        </header>
        <p class="message">
          Se exportarán <strong>{{ filasCount }}</strong> fila(s) visibles (filtros de columna aplicados).
        </p>
        <footer class="modal-footer">
          <button type="button" class="btn-cancel" @click="emit('cancel')">Cancelar</button>
          <button type="button" class="btn-secondary" @click="emit('excel')">Excel (CSV)</button>
          <button type="button" class="btn-confirm" @click="emit('imprimir')">Vista previa / Imprimir</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 9000;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal {
  background: #fff;
  border-radius: 10px;
  max-width: 420px;
  width: 100%;
  padding: 1rem 1.15rem 1.1rem;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
}
.modal-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.65rem;
}
.modal-header h3 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}
.header-icon {
  color: #0f172a;
}
.message {
  margin: 0 0 1rem;
  font-size: 0.92rem;
  color: #334155;
  line-height: 1.45;
}
.modal-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: flex-end;
}
.btn-cancel,
.btn-secondary,
.btn-confirm {
  border-radius: 6px;
  padding: 0.45rem 0.85rem;
  font-size: 0.88rem;
  cursor: pointer;
  border: 1px solid #cbd5e1;
  background: #fff;
}
.btn-secondary {
  background: #f8fafc;
}
.btn-confirm {
  background: #0f172a;
  color: #fff;
  border-color: #0f172a;
}
</style>
