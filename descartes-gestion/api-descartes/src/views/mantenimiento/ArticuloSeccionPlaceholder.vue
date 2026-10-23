<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const titulo = computed(() => String(route.meta.titulo ?? 'Seccion'))
</script>

<template>
  <section class="placeholder">
    <h2>{{ titulo }}</h2>
    <p>Esta seccion se implementara proximamente.</p>
  </section>
</template>

<style scoped>
.placeholder h2 {
  margin: 0 0 0.5rem;
}

.placeholder p {
  margin: 0;
  color: #64748b;
}
</style>
