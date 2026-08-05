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
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Estadistica" @click="$emit('estadistica')">
        <ToolIcon name="estadistica" />
        <span>Estadistica</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Excepciones" @click="$emit('excepciones')">
        <ToolIcon name="excepciones" />
        <span>Excepciones</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Contactos" @click="$emit('contactos')">
        <ToolIcon name="contactos" />
        <span>Contactos</span>
      </button>
      <button type="button" class="tool-btn" :disabled="loading || !hayProveedor" title="Intereses" @click="$emit('intereses')">
        <ToolIcon name="intereses" />
        <span>Intereses</span>
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
  position: relative;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
  padding: 0.75rem;
  background: linear-gradient(180deg, #f8fafc 0%, #e5e7eb 100%);
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  margin-bottom: 0.75rem;
}

.toolbar-group {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.toolbar-group.nav {
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
}

.toolbar > .toolbar-group:last-child {
  margin-left: auto;
}

.tool-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.15rem;
  min-width: 4.2rem;
  padding: 0.35rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.7rem;
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
  border-radius: 8px;
  padding: 0.25rem 0.5rem;
}

.nav-btn {
  border: 1px solid #86efac;
  background: #dcfce7;
  color: #166534;
  border-radius: 6px;
  padding: 0.2rem 0.45rem;
  cursor: pointer;
}

.nav-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.nav-counter {
  min-width: 3rem;
  text-align: center;
  font-size: 0.85rem;
  font-weight: 600;
}
</style>

