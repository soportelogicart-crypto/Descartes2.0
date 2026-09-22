<script setup lang="ts">
/** Overlay modal sin cierre: bloquea la UI hasta que `open` pase a false. */
withDefaults(
  defineProps<{
    open: boolean
    titulo?: string
    mensaje?: string
  }>(),
  {
    titulo: 'Espere',
    mensaje: 'Guardando…',
  },
)
</script>

<template>
  <Teleport to="body">
    <div
      v-show="open"
      class="espera-overlay"
      role="alertdialog"
      aria-modal="true"
      :aria-busy="open ? 'true' : 'false'"
      :aria-label="titulo"
    >
      <div class="espera-card">
        <div class="espera-spinner" aria-hidden="true" />
        <h3 class="espera-titulo">{{ titulo }}</h3>
        <p class="espera-mensaje">{{ mensaje }}</p>
        <p class="espera-aviso">No cierre esta ventana ni cambie de pantalla hasta que termine.</p>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.espera-overlay {
  position: fixed;
  inset: 0;
  z-index: 6000;
  display: grid;
  place-items: center;
  background: rgba(15, 23, 42, 0.55);
  padding: 1rem;
  pointer-events: all;
}

.espera-card {
  width: min(22rem, 100%);
  background: #fff;
  border-radius: 8px;
  padding: 1.25rem 1.35rem 1.1rem;
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.25);
  text-align: center;
}

.espera-spinner {
  width: 2.25rem;
  height: 2.25rem;
  margin: 0 auto 0.85rem;
  border: 3px solid #e2e8f0;
  border-top-color: #0ea5e9;
  border-radius: 50%;
  animation: espera-spin 0.75s linear infinite;
}

@keyframes espera-spin {
  to {
    transform: rotate(360deg);
  }
}

.espera-titulo {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  color: #0f172a;
}

.espera-mensaje {
  margin: 0 0 0.65rem;
  font-size: 0.92rem;
  color: #334155;
}

.espera-aviso {
  margin: 0;
  font-size: 0.78rem;
  line-height: 1.35;
  color: #64748b;
}
</style>
