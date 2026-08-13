<script setup lang="ts">
/**
 * Shell legacy del módulo Etiquetas (T003).
 * La ruta activa usa EtiquetasColaView; este componente queda como contenedor mínimo.
 */
withDefaults(
  defineProps<{
    titulo?: string
  }>(),
  { titulo: 'Etiquetas' }
)
</script>

<template>
  <section class="etiquetas-shell">
    <h2>{{ titulo }}</h2>
    <p>Use el menú <strong>Etiquetas</strong> (cola de impresión).</p>
  </section>
</template>

<style scoped>
.etiquetas-shell {
  max-width: 40rem;
}

.etiquetas-shell h2 {
  margin: 0 0 0.5rem;
}

.etiquetas-shell p {
  margin: 0;
  color: #64748b;
  font-size: 0.95rem;
}
</style>
