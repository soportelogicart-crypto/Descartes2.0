<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useTabsStore, type AppTab } from '@/stores/tabs'

const tabs = useTabsStore()
const router = useRouter()

function normalizarPath(path: string): string {
  return (path.split('?')[0] || '/').replace(/\/+$/, '') || '/'
}

async function activar(tab: AppTab) {
  const pathActual = normalizarPath(router.currentRoute.value.fullPath)
  const pathTab = normalizarPath(tab.fullPath)
  if (tabs.activeId === tab.id && pathActual === pathTab) return
  await router.push(tab.fullPath)
}

async function cerrar(e: Event, id: string) {
  e.preventDefault()
  e.stopPropagation()
  const next = tabs.close(id)
  if (next !== null) {
    await router.push(next)
  }
}

async function cerrarOtras(e: Event, id: string) {
  // En táctil un doble toque es involuntario y esto cierra todo lo demás:
  // el atajo queda reservado a puestos con ratón.
  if (!window.matchMedia('(pointer: fine)').matches) return
  e.preventDefault()
  e.stopPropagation()
  tabs.closeOthers(id)
  const t = tabs.activa
  if (t) await router.push(t.fullPath)
}
</script>

<template>
  <div v-if="tabs.activas.length" class="tabs-bar" role="tablist" aria-label="Pestañas abiertas">
    <button
      v-for="t in tabs.activas"
      :key="t.id"
      type="button"
      role="tab"
      class="tab"
      :class="{ active: t.id === tabs.activeId }"
      :title="t.fullPath"
      :aria-selected="t.id === tabs.activeId"
      @click="activar(t)"
      @dblclick="cerrarOtras($event, t.id)"
    >
      <span class="tab-title">{{ t.title }}</span>
      <!--
        Solo se puede cerrar la pestaña activa: en táctil, cambiar de pestaña
        es un gesto de un toque y la aspa quedaba al alcance del dedo, así que
        se cerraban sin querer. En las inactivas se reserva el hueco para que
        todas midan lo mismo.
      -->
      <span
        class="tab-close"
        :class="{ oculta: t.id !== tabs.activeId }"
        title="Cerrar"
        role="button"
        :tabindex="t.id === tabs.activeId ? 0 : -1"
        :aria-hidden="t.id !== tabs.activeId"
        @click="cerrar($event, t.id)"
        @keydown.enter="cerrar($event, t.id)"
      >
        ×
      </span>
    </button>
  </div>
</template>

<style scoped>
.tabs-bar {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.15rem;
  overflow-x: auto;
  padding: 0.35rem 0.75rem 0;
  background: #e8eef5;
  border-bottom: 1px solid #c5d0dc;
  flex-shrink: 0;
}
.tab {
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
  /* Ancho mínimo: con nombres cortos la pestaña quedaba estrecha y la aspa
     pegada al texto, lo que provocaba cierres accidentales en táctil. */
  min-width: 8rem;
  max-width: 11rem;
  padding: 0.35rem 0.45rem 0.35rem 0.65rem;
  border: 1px solid #c5d0dc;
  border-bottom: none;
  border-radius: 6px 6px 0 0;
  background: #d7e0ea;
  color: #334155;
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
  flex-shrink: 0;
}
.tab:hover {
  background: #eef3f8;
}
.tab.active {
  background: #fff;
  color: #0f172a;
  font-weight: 600;
  border-color: #94a3b8;
  z-index: 1;
}
.tab-title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tab-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.35rem;
  height: 1.35rem;
  border-radius: 3px;
  font-size: 1rem;
  line-height: 1;
  color: #64748b;
  flex-shrink: 0;
}
.tab-close:hover {
  background: #cbd5e1;
  color: #0f172a;
}
.tab-close.oculta {
  visibility: hidden;
  pointer-events: none;
}

</style>
