<script setup lang="ts">
defineProps<{
  titulo: string
}>()
</script>

<template>
  <section class="modulo-placeholder">
    <h2>{{ titulo }}</h2>
    <p>Modulo en construccion. Se completara en siguientes fases.</p>
  </section>
</template>

<style scoped>
.modulo-placeholder {
  max-width: 40rem;
}

.modulo-placeholder h2 {
  margin: 0 0 0.5rem;
}

.modulo-placeholder p {
  margin: 0;
  color: #64748b;
  font-size: 0.95rem;
}
</style>
