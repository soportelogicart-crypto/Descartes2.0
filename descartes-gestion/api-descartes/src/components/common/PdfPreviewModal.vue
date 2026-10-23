<script setup lang="ts">
defineProps<{
  open: boolean
  url: string | null
  titulo?: string
}>()

const emit = defineEmits<{
  cerrar: []
}>()
</script>

<template>
  <Teleport to="body">
    <div v-if="open && url" class="pdf-overlay" @click.self="emit('cerrar')">
      <div class="pdf-modal" role="dialog" aria-modal="true" aria-label="Previsualización PDF">
        <header class="pdf-head">
          <h3>{{ titulo || 'Previsualización PDF' }}</h3>
          <div class="pdf-actions">
            <a class="btn" :href="url" target="_blank" rel="noopener">Abrir en pestaña</a>
            <button type="button" class="btn primary" @click="emit('cerrar')">Cerrar</button>
          </div>
        </header>
        <iframe class="pdf-frame" :src="url" title="Previsualización PDF" />
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.pdf-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.55);
}
.pdf-modal {
  display: flex;
  flex-direction: column;
  width: min(1100px, 96vw);
  height: min(90vh, 900px);
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
}
.pdf-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}
.pdf-head h3 {
  margin: 0;
  font-size: 1rem;
  color: #0f172a;
}
.pdf-actions {
  display: flex;
  gap: 0.35rem;
  align-items: center;
}
.btn {
  padding: 0.35rem 0.7rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
  color: #0f172a;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
}
.btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}
.pdf-frame {
  flex: 1;
  width: 100%;
  border: 0;
  background: #525659;
}
</style>
