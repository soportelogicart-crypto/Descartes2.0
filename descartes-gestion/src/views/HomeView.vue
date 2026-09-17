<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const avisoPermiso = computed(() => {
  const m = route.query.sinPermiso
  if (typeof m !== 'string' || !m.trim()) return null
  const etiquetas: Record<string, string> = {
    listados: 'Listados',
    'ventas-abc': 'ABC de ventas',
  }
  const nombre = etiquetas[m] ?? m
  return `No tiene permiso para acceder a «${nombre}». Si necesita entrar, pida al administrador que active el módulo en su rol.`
})

function cerrarAviso() {
  void router.replace({ name: 'home', query: {} })
}
</script>

<template>
  <section class="home" aria-label="Inicio">
    <p v-if="avisoPermiso" class="aviso-permiso" role="alert">
      {{ avisoPermiso }}
      <button type="button" class="aviso-cerrar" @click="cerrarAviso">Entendido</button>
    </p>
  </section>
</template>

<style scoped>
.home {
  min-height: 100%;
  padding: 1rem 1.25rem;
}
.aviso-permiso {
  max-width: 36rem;
  margin: 0;
  padding: 0.85rem 1rem;
  border-radius: 8px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
  font-size: 0.92rem;
  line-height: 1.45;
}
.aviso-cerrar {
  display: block;
  margin-top: 0.65rem;
  padding: 0.35rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #fca5a5;
  background: #fff;
  color: #991b1b;
  cursor: pointer;
  font-size: 0.88rem;
}
</style>
