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
  hayProveedor?: boolean
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
  estadistica: []
  excepciones: []
  contactos: []
  intereses: []
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
        :disabled="loading || !puedeEditar || modoEdicion || !hayProveedor"
        title="Modificar"
        @click="$emit('modificar')"
      >
        <ToolIcon name="modificar" />
        <span>Modificar</span>
      </button>
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || !puedeEliminar || !hayProveedor"
        title="Borrar"
        @click="$emit('borrar')"
      >
        <ToolIcon name="borrar" />
        <span>Borrar</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading" title="Buscar" @click="$emit('buscar')">
        <ToolIcon name="buscar" />
        <span>Buscar</span>
      </button>
    </div>

    <div class="toolbar-group nav">
      <button type="button" class="nav-btn" :disabled="loading || (indice ?? -1) < 0" title="Primero" @click="$emit('primero')">|&lt;</button>
      <button type="button" class="nav-btn" :disabled="loading || (indice ?? -1) <= 0" title="Anterior" @click="$emit('anterior')">&lt;</button>
      <span class="nav-counter">
        {{ (indice ?? -1) >= 0 && total ? `${(indice ?? 0) + 1}/${total}` : `—/${total ?? 0}` }}
      </span>
      <button
        type="button"
        class="nav-btn"
        title="Siguiente"
        :disabled="loading || total === 0 || (indice ?? -1) < 0 || (indice ?? -1) >= (total ?? 1) - 1"
        @click="$emit('siguiente')"
      >
        &gt;
      </button>
      <button
        type="button"
        class="nav-btn"
        title="Ultimo"
        :disabled="loading || total === 0 || (indice ?? -1) < 0 || (indice ?? -1) >= (total ?? 1) - 1"
        @click="$emit('ultimo')"
      >
        &gt;|
      </button>
    </div>

    <div class="toolbar-group">
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Estadistica" @click="$emit('estadistica')">
        <ToolIcon name="estadistica" />
        <span>Estadist.</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Contactos" @click="$emit('contactos')">
        <ToolIcon name="contactos" />
        <span>Contactos</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Intereses" @click="$emit('intereses')">
        <ToolIcon name="intereses" />
        <span>Intereses</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Excepciones" @click="$emit('excepciones')">
        <ToolIcon name="excepciones" />
        <span>Excepc.</span>
      </button>
    </div>

    <div class="toolbar-group actions">
      <button
        v-if="modoEdicion"
        type="button"
        class="tool-btn primary"
        title="Guardar"
        :disabled="loading || !puedeGuardar"
        @click="$emit('guardar')"
      >
        <ToolIcon name="guardar" />
        <span>Guardar</span>
      </button>
      <button
        v-if="modoEdicion"
        type="button"
        class="tool-btn"
        title="Cancelar"
        :disabled="loading"
        @click="$emit('cancelar')"
      >
        <span>Cancelar</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.3rem;
  align-items: center;
  width: 100%;
  box-sizing: border-box;
  padding: 0.3rem 0.4rem;
  background: linear-gradient(180deg, #f8fafc 0%, #e8edf3 100%);
  border: 1px solid #c5cdd8;
  border-bottom: 1px solid #cbd5e1;
  border-radius: 8px 8px 0 0;
  margin: 0;
  overflow-x: auto;
}

.toolbar-group {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.2rem;
  align-items: center;
  flex-shrink: 0;
}

.toolbar-group.actions {
  margin-left: auto;
}

.tool-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.05rem;
  min-width: 3.35rem;
  padding: 0.2rem 0.25rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  font-size: 0.62rem;
  line-height: 1.05;
  cursor: pointer;
  color: #1e293b;
  white-space: nowrap;
}

.tool-btn :deep(.tool-icon) {
  width: 1.05rem;
  height: 1.05rem;
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
  padding: 0.12rem 0.3rem;
}

.nav-btn {
  border: 1px solid #86efac;
  background: #dcfce7;
  color: #166534;
  border-radius: 4px;
  padding: 0.12rem 0.3rem;
  font-size: 0.7rem;
  cursor: pointer;
}

.nav-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.nav-counter {
  min-width: 2.6rem;
  text-align: center;
  font-size: 0.72rem;
  font-weight: 600;
}
</style>
