<script setup lang="ts">
/**
 * Shell del módulo Compras (T003/T007).
 * Listados reales: T015+ (albaranes), T027+ (pedidos), T033+ (facturas).
 */
withDefaults(
  defineProps<{
    titulo?: string
  }>(),
  { titulo: 'Compras' }
)
</script>

<template>
  <section class="compras-shell">
    <h2>{{ titulo }}</h2>
    <p>Módulo en construcción. Se completará en siguientes fases.</p>
  </section>
</template>

<style scoped>
.compras-shell {
  max-width: 40rem;
}

.compras-shell h2 {
  margin: 0 0 0.5rem;
}

.compras-shell p {
  margin: 0;
  color: #64748b;
  font-size: 0.95rem;
}
</style>
