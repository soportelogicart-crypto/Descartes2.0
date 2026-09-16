<script setup lang="ts">
import { RouterLink } from 'vue-router'

defineProps<{
  titulo: string
  descripcion?: string
  generando?: boolean
  tieneDatos?: boolean
  exportando?: boolean
}>()

const emit = defineEmits<{
  generar: []
  excel: []
  imprimir: []
}>()
</script>

<template>
  <section class="listado-informe">
    <header class="cabecera">
      <div>
        <RouterLink to="/listados" class="volver">← Listados</RouterLink>
        <h2>{{ titulo }}</h2>
        <p v-if="descripcion" class="desc">{{ descripcion }}</p>
      </div>
      <div class="acciones">
        <button type="button" class="btn primary" :disabled="generando" @click="emit('generar')">
          {{ generando ? 'Generando…' : 'Generar' }}
        </button>
        <button type="button" class="btn" :disabled="!tieneDatos || exportando" @click="emit('excel')">
          Excel
        </button>
        <button type="button" class="btn" :disabled="!tieneDatos || exportando" @click="emit('imprimir')">
          Vista previa / Imprimir
        </button>
      </div>
    </header>

    <div class="filtros">
      <slot name="filtros" />
    </div>

    <details v-if="$slots.masFiltros" class="mas-filtros">
      <summary>Más filtros</summary>
      <div class="mas-filtros-body">
        <slot name="mas-filtros" />
      </div>
    </details>

    <slot name="aviso" />

    <div class="resultado">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.listado-informe {
  max-width: 72rem;
}

.cabecera {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1rem;
}

.volver {
  display: inline-block;
  font-size: 0.85rem;
  color: #475569;
  text-decoration: none;
  margin-bottom: 0.35rem;
}

.volver:hover {
  color: #0f172a;
}

.cabecera h2 {
  margin: 0;
  font-size: 1.35rem;
}

.desc {
  margin: 0.35rem 0 0;
  color: #64748b;
  font-size: 0.88rem;
  max-width: 36rem;
}

.acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.btn {
  padding: 0.45rem 0.85rem;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.88rem;
}

.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn.primary {
  background: #0f172a;
  border-color: #0f172a;
  color: #fff;
}

.filtros {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  align-items: flex-end;
  margin-bottom: 0.75rem;
}

.filtros :deep(label) {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.8rem;
  color: #475569;
}

.filtros :deep(select),
.filtros :deep(input[type='date']),
.filtros :deep(input[type='text']) {
  min-width: 10rem;
  padding: 0.35rem 0.5rem;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  font-size: 0.9rem;
}

.mas-filtros {
  margin-bottom: 1rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 0.5rem 0.75rem;
  background: #f8fafc;
}

.mas-filtros summary {
  cursor: pointer;
  font-size: 0.88rem;
  font-weight: 600;
  color: #334155;
}

.mas-filtros-body {
  margin-top: 0.75rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  align-items: flex-end;
}

.resultado {
  margin-top: 0.5rem;
}
</style>
