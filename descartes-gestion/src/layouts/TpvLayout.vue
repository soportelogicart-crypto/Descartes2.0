<script setup lang="ts">
import { RouterView } from 'vue-router'
</script>

<template>
  <div class="tpv-layout">
    <RouterView />
  </div>
</template>

<style scoped>
.tpv-layout {
  min-height: 100vh;
  min-height: 100dvh;
  width: 100%;
  background: #0f172a;
  color: #f8fafc;
  overflow: hidden;
}
</style>

<style>
/* TPV fullscreen: ocultar scroll global en esta ruta */
body:has(.tpv-layout) {
  overflow: hidden;
}
</style>
