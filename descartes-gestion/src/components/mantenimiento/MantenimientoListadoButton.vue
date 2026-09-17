<script setup lang="ts">
import { computed, ref } from 'vue'
import type { GridOptionsMap } from '@/components/mantenimiento/EntidadGrid.vue'
import {
  exportarGridExcel,
  exportarGridImprimir,
  filasVisiblesGrid,
  type ListadoGridColumnDef,
} from '@/composables/exportGridListado'
import { useAuthStore } from '@/stores/auth'
import ListadoGridDialog from '@/components/mantenimiento/ListadoGridDialog.vue'

const props = defineProps<{
  titulo: string
  columnas: ListadoGridColumnDef[]
  filas: Record<string, unknown>[]
  optionsMap?: GridOptionsMap
  slugArchivo?: string
}>()

const emit = defineEmits<{
  aviso: [string | null]
}>()

const auth = useAuthStore()
const open = ref(false)

const filasVisibles = computed(() => filasVisiblesGrid(props.filas))

function abrir() {
  if (!filasVisibles.value.length) {
    emit('aviso', 'No hay filas visibles para el listado. Ajuste filtros o cargue datos.')
    return
  }
  open.value = true
}

function metaLineas(): string[] {
  const lines = [`${filasVisibles.value.length} fila(s)`]
  const u = auth.usuario?.nombre
  if (u) lines.push(`Usuario: ${u}`)
  lines.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lines
}

function slug(): string {
  if (props.slugArchivo?.trim()) return props.slugArchivo.trim()
  return props.titulo
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

function onExcel() {
  exportarGridExcel({
    nombreArchivo: `${slug()}.csv`,
    columnas: props.columnas,
    filas: props.filas,
    optionsMap: props.optionsMap,
  })
  open.value = false
  emit('aviso', 'Excel (CSV) exportado')
}

function onImprimir() {
  const msg = exportarGridImprimir({
    titulo: props.titulo,
    columnas: props.columnas,
    filas: props.filas,
    optionsMap: props.optionsMap,
    metaLineas: metaLineas(),
    slugArchivo: slug(),
  })
  open.value = false
  emit('aviso', msg)
}

function cerrar() {
  open.value = false
}
</script>

<template>
  <button type="button" class="tool-btn" @click="abrir">
    <slot>Listado</slot>
  </button>
  <ListadoGridDialog
    :open="open"
    :titulo="titulo"
    :filas-count="filasVisibles.length"
    @excel="onExcel"
    @imprimir="onImprimir"
    @cancel="cerrar"
  />
</template>
