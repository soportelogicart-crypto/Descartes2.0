<script setup lang="ts">
import ToolIcon from '@/components/common/ToolIcon.vue'

defineProps<{
  puedeCrear?: boolean
  puedeEditar?: boolean
  puedeEliminar?: boolean
  puedeGuardar?: boolean
  modoEdicion?: boolean
  indice?: number
  total?: number
  loading?: boolean
}>()

defineEmits<{
  nuevo: []
  modificar: []
  borrar: []
  buscar: []
  guardar: []
  cancelar: []
  primero: []
  anterior: []
  siguiente: []
  ultimo: []
}>()
</script>

<template>
  <div class="toolbar">
    <div class="toolbar-group">
      <button type="button" class="tool-btn" :disabled="loading || !puedeCrear" title="Nuevo" @click="$emit('nuevo')">
        <ToolIcon name="nuevo" />
        <span>Nuevo</span>
      </button>
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || !puedeEditar || modoEdicion"
        title="Modificar"
        @click="$emit('modificar')"
      >
        <ToolIcon name="modificar" />
        <span>Modificar</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading || !puedeEliminar" title="Borrar" @click="$emit('borrar')">
        <ToolIcon name="borrar" />
        <span>Borrar</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading" title="Buscar" @click="$emit('buscar')">
        <ToolIcon name="buscar" />
        <span>Buscar</span>
      </button>
    </div>

    <div class="toolbar-group nav">
      <button type="button" class="nav-btn" :disabled="loading || (indice ?? -1) < 0" @click="$emit('primero')">|&lt;</button>
      <button type="button" class="nav-btn" :disabled="loading || (indice ?? -1) <= 0" @click="$emit('anterior')">&lt;</button>
      <span class="nav-counter">{{ total ? ((indice ?? -1) + 1) : 0 }}/{{ total ?? 0 }}</span>
      <button
        type="button"
        class="nav-btn"
        :disabled="loading || total === 0 || (indice ?? -1) < 0 || (indice ?? -1) >= (total ?? 1) - 1"
        @click="$emit('siguiente')"
      >
        &gt;
      </button>
      <button
        type="button"
        class="nav-btn"
        :disabled="loading || total === 0 || (indice ?? -1) < 0 || (indice ?? -1) >= (total ?? 1) - 1"
        @click="$emit('ultimo')"
      >
        &gt;|
      </button>
    </div>

    <div class="toolbar-group">
      <button
        v-if="modoEdicion"
        type="button"
        class="tool-btn primary"
        :disabled="loading || !puedeGuardar"
        @click="$emit('guardar')"
      >
        <ToolIcon name="guardar" />
        <span>Guardar</span>
      </button>
      <button v-if="modoEdicion" type="button" class="tool-btn" :disabled="loading" @click="$emit('cancelar')">
        Cancelar
      </button>
    </div>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
  width: 100%;
  max-width: none;
  box-sizing: border-box;
  padding: 0.35rem 0.45rem;
  background: linear-gradient(180deg, #f8fafc 0%, #e5e7eb 100%);
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  margin-bottom: 0.5rem;
}

.toolbar-group {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  align-items: center;
}

.toolbar-group.nav {
  margin-left: auto;
  margin-right: auto;
}

.toolbar > .toolbar-group:last-child:not(.nav) {
  margin-left: auto;
}

.tool-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.1rem;
  min-width: 3.4rem;
  padding: 0.25rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  font-size: 0.68rem;
  cursor: pointer;
  color: #1e293b;
}

.tool-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.tool-btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.nav {
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 0.15rem 0.35rem;
}

.nav-btn {
  border: 1px solid #86efac;
  background: #dcfce7;
  color: #166534;
  border-radius: 5px;
  padding: 0.15rem 0.35rem;
  cursor: pointer;
  font-size: 0.75rem;
}

.nav-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.nav-counter {
  min-width: 2.5rem;
  text-align: center;
  font-size: 0.78rem;
  font-weight: 600;
}
</style>
