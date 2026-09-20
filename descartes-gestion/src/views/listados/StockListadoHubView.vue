<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { STOCK_LISTADOS } from '@/config/stock-listado-config'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { usePermisos } from '@/composables/usePermisos'
import { puedeVerSubmenuStock } from '@/config/listados-permisos'

const router = useRouter()
const { registrarReciente } = useListadosRecientes()
const { puede } = usePermisos()

const opciones = computed(() =>
  STOCK_LISTADOS.map((item) => ({
    item,
    habilitado: puedeVerSubmenuStock(puede, item.agruparPor),
  })),
)

onMounted(() => registrarReciente('stock'))

function abrir(agruparPor: string, habilitado: boolean) {
  if (!habilitado) return
  registrarReciente('stock')
  void router.push({ name: 'listados-stock', params: { agruparPor } })
}

function etiquetaCorta(titulo: string): string {
  const m = titulo.match(/\(([^)]+)\)/)
  return m?.[1] ?? titulo
}
</script>

<template>
  <section class="stock-hub">
    <header class="cabecera">
      <h2>Listado de stock</h2>
      <p class="intro">Elija cómo agrupar las existencias (menú Inventario legacy).</p>
    </header>
    <ul class="opciones">
      <li v-for="row in opciones" :key="row.item.catalogId">
        <button
          type="button"
          class="opcion"
          :class="{ 'opcion-disabled': !row.habilitado }"
          :disabled="!row.habilitado"
          :title="row.habilitado ? undefined : 'Sin permiso para este listado'"
          @click="abrir(row.item.agruparPor, row.habilitado)"
        >
          <span class="opcion-titulo">{{ etiquetaCorta(row.item.titulo) }}</span>
          <span class="opcion-desc">{{ row.item.subtitulo }}</span>
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.stock-hub {
  max-width: 36rem;
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
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.opcion {
  width: 100%;
  text-align: left;
  padding: 0.55rem 0.65rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font: inherit;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
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
  font-size: 0.92rem;
}

.opcion-desc {
  font-size: 0.78rem;
  color: #64748b;
}
</style>
