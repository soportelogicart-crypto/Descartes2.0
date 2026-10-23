<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ABC_COMPRAS_LISTADOS } from '@/config/abc-compras-dimensiones'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { usePermisos } from '@/composables/usePermisos'
import { puedeVerSubmenuAbcCompras } from '@/config/listados-permisos'

const router = useRouter()
const { registrarReciente } = useListadosRecientes()
const { puede } = usePermisos()

const opciones = computed(() =>
  ABC_COMPRAS_LISTADOS.map((item) => ({
    item,
    habilitado: puedeVerSubmenuAbcCompras(puede, item.dimension),
  })),
)

onMounted(() => registrarReciente('abc-compras'))

function abrir(dimension: string, habilitado: boolean) {
  if (!habilitado) return
  registrarReciente('abc-compras')
  void router.push({ name: 'listados-abc-compras', params: { dimension } })
}

function etiquetaCorta(titulo: string): string {
  const m = titulo.match(/\(([^)]+)\)/)
  return m?.[1] ?? titulo
}
</script>

<template>
  <section class="abc-hub">
    <header class="cabecera">
      <h2>ABC de compras</h2>
      <p class="intro">Elija la dimensión de agrupación (menú ComprasAbc legacy).</p>
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
