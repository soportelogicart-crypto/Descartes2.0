<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ABC_VENTAS_LISTADOS } from '@/config/abc-ventas-dimensiones'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { usePermisos } from '@/composables/usePermisos'
import { puedeVerSubmenuAbc } from '@/config/listados-permisos'

const router = useRouter()
const { registrarReciente } = useListadosRecientes()
const { puede } = usePermisos()

const opciones = computed(() =>
  ABC_VENTAS_LISTADOS.map((item) => ({
    item,
    habilitado: puedeVerSubmenuAbc(puede, item.dimension),
  })),
)

onMounted(() => registrarReciente('abc-ventas'))

function abrir(dimension: string, habilitado: boolean) {
  if (!habilitado) return
  registrarReciente('abc-ventas')
  void router.push({ name: 'listados-abc-ventas', params: { dimension } })
}

function etiquetaCorta(titulo: string): string {
  const m = titulo.match(/\(([^)]+)\)/)
  return m?.[1] ?? titulo
}
</script>

<template>
  <section class="abc-hub">
    <header class="cabecera">
      <h2>ABC de ventas</h2>
      <p class="intro">Elija la dimensión de agrupación (menú VentasABC legacy).</p>
    </header>
    <ul class="opciones">
      <li v-for="row in opciones" :key="row.item.catalogId">
        <button
          type="button"
          class="opcion"
          :class="{ 'opcion-disabled': !row.habilitado }"
          :disabled="!row.habilitado"
          :title="row.habilitado ? undefined : 'Sin permiso para este listado'"
          @click="abrir(row.item.dimension, row.habilitado)"
        >
          <span class="opcion-titulo">{{ etiquetaCorta(row.item.titulo) }}</span>
          <span class="opcion-desc">{{ row.item.subtitulo }}</span>
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.abc-hub {
  max-width: 48rem;
  padding: 0.25rem 0;
}

.cabecera h2 {
  margin: 0 0 0.35rem;
}

.intro {
  margin: 0 0 1rem;
  color: #64748b;
  font-size: 0.88rem;
}

.opciones {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem 0.5rem;
  align-content: start;
}

.opciones li {
  min-width: 0;
}

.opcion {
  width: 100%;
  height: 100%;
  box-sizing: border-box;
  text-align: left;
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font: inherit;
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
  align-items: flex-start;
}

.opcion:hover:not(:disabled) {
  border-color: #38bdf8;
  background: #f0f9ff;
}

.opcion-disabled,
.opcion:disabled {
  cursor: not-allowed;
  opacity: 0.55;
  background: #f1f5f9;
}

.opcion-titulo {
  font-weight: 600;
  color: #1e293b;
  font-size: 0.88rem;
  line-height: 1.2;
}

.opcion-desc {
  font-size: 0.72rem;
  line-height: 1.25;
  color: #64748b;
  text-align: left;
}

@media (max-width: 520px) {
  .opciones {
    grid-template-columns: 1fr;
  }
}
</style>
