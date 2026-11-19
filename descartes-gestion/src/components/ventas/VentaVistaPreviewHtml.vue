<script setup lang="ts">
/**
 * Vista previa de un documento A4 dentro de la propia página. Muestra el mismo
 * HTML que se imprime, así que no necesita ventanas emergentes.
 */
defineProps<{
  open: boolean
  titulo: string
  html: string
  impresoraNombre: string
  imprimiendo?: boolean
  error?: string
}>()

const emit = defineEmits<{
  cerrar: []
  imprimir: []
}>()
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @click.self="emit('cerrar')">
      <div class="modal-preview" role="dialog" aria-modal="true">
        <header>
          <h3>{{ titulo }}</h3>
          <p class="meta">
            Impresora por defecto:
            <strong>{{ impresoraNombre || '— (sin asignar en el puesto)' }}</strong>
          </p>
        </header>
        <div class="vista">
          <iframe :srcdoc="html" class="marco" title="Vista previa del documento" />
        </div>
        <footer>
          <span v-if="error" class="error">{{ error }}</span>
          <button type="button" @click="emit('cerrar')">Cerrar</button>
          <button
            type="button"
            class="primary"
            :disabled="!html || imprimiendo"
            @click="emit('imprimir')"
          >
            {{ imprimiendo ? 'Imprimiendo…' : 'Imprimir' }}
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
  z-index: 80;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal-preview {
  width: min(920px, 100%);
  max-height: 92vh;
  display: flex;
  flex-direction: column;
  background: #f8fafc;
  border-radius: 10px;
  box-shadow: 0 18px 50px rgba(15, 23, 42, 0.35);
  overflow: hidden;
}
header {
  padding: 0.85rem 1rem 0.5rem;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
}
header h3 {
  margin: 0;
  font-size: 1.05rem;
}
.meta {
  margin: 0.35rem 0 0;
  font-size: 0.85rem;
  color: #475569;
}
.vista {
  flex: 1;
  min-height: 0;
  overflow: auto;
  padding: 0.75rem;
  background: #e2e8f0;
  display: flex;
  justify-content: center;
}
.marco {
  width: 820px;
  max-width: 100%;
  height: 78vh;
  border: 0;
  background: #fff;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.25);
}
footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.65rem 1rem;
  border-top: 1px solid #e2e8f0;
  background: #fff;
}
footer .error {
  margin-right: auto;
  color: #b91c1c;
  font-size: 0.85rem;
}
button {
  padding: 0.4rem 0.85rem;
  border-radius: 6px;
  border: 1px solid #cbd5e1;
  background: #fff;
  cursor: pointer;
}
button.primary {
  background: #0f766e;
  border-color: #0f766e;
  color: #fff;
}
button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
</style>
