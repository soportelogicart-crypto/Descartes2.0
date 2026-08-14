<script setup lang="ts">
import { withDefaults } from 'vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

withDefaults(
  defineProps<{
    puedeCrear?: boolean
    puedeEditar?: boolean
    puedeEliminar?: boolean
    puedeGuardar?: boolean
    puedeImprimir?: boolean
    puedeFinalizar?: boolean
    puedeAbonar?: boolean
    /** Albarán compra: legacy Recuperar (revertir stock). */
    puedeRecuperar?: boolean
    /** Albarán compra: legacy Actualizar stock. */
    puedeActualizarStock?: boolean
    /** Pedido venta: generar albarán/ticket al cliente desde pedido guardado. */
    puedeGenerarAlbaran?: boolean
    /** Etiqueta del botón generar albarán (default «Albarán»). */
    generarAlbaranLabel?: string
    /** Tooltip del botón generar albarán. */
    generarAlbaranTitle?: string
    puedeBuscar?: boolean
    /** Etiqueta del botón Buscar (p. ej. «Listado» en fichas). */
    buscarLabel?: string
    /** Tooltip del botón Buscar. */
    buscarTitle?: string
    puedeNavegar?: boolean
    modoEdicion?: boolean
    bloqueado?: boolean
    esTicketCerrado?: boolean
    hayDocumento?: boolean
    loading?: boolean
    indice?: number
    total?: number
  }>(),
  {
    buscarLabel: 'Buscar',
    buscarTitle: 'Volver al listado',
    generarAlbaranLabel: 'Albarán',
    generarAlbaranTitle:
      'Generar albarán de venta al cliente desde las cantidades a servir del pedido',
  }
)

defineEmits<{
  nuevo: []
  modificar: []
  borrar: []
  buscar: []
  guardar: []
  cancelar: []
  finalizar: []
  abonar: []
  recuperar: []
  actualizarStock: []
  generarAlbaran: []
  primero: []
  anterior: []
  siguiente: []
  ultimo: []
  imprimir: []
}>()
</script>

<template>
  <div class="toolbar">
    <div class="toolbar-group">
      <button
        type="button"
        class="tool-btn"
        :disabled="loading"
        title="Nuevo"
        @click="$emit('nuevo')"
      >
        <ToolIcon name="nuevo" />
        <span>Nuevo</span>
      </button>
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || !puedeEditar || modoEdicion || !hayDocumento || bloqueado"
        title="Modificar"
        @click="$emit('modificar')"
      >
        <ToolIcon name="modificar" />
        <span>Modificar</span>
      </button>
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || !puedeEliminar || !hayDocumento || bloqueado || modoEdicion"
        title="Borrar"
        @click="$emit('borrar')"
      >
        <ToolIcon name="borrar" />
        <span>Borrar</span>
      </button>
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || puedeBuscar === false"
        :title="buscarTitle"
        @click="$emit('buscar')"
      >
        <ToolIcon name="buscar" />
        <span>{{ buscarLabel }}</span>
      </button>
    </div>

    <div class="toolbar-group nav">
      <button
        type="button"
        class="nav-btn"
        :disabled="loading || puedeNavegar === false || (indice ?? -1) < 0"
        @click="$emit('primero')"
      >
        |&lt;
      </button>
      <button
        type="button"
        class="nav-btn"
        :disabled="loading || puedeNavegar === false || (indice ?? -1) <= 0"
        @click="$emit('anterior')"
      >
        &lt;
      </button>
      <span class="nav-counter">{{ total ? (indice ?? -1) + 1 : 0 }}/{{ total ?? 0 }}</span>
      <button
        type="button"
        class="nav-btn"
        :disabled="
          loading ||
          puedeNavegar === false ||
          total === 0 ||
          (indice ?? -1) < 0 ||
          (indice ?? -1) >= (total ?? 1) - 1
        "
        @click="$emit('siguiente')"
      >
        &gt;
      </button>
      <button
        type="button"
        class="nav-btn"
        :disabled="
          loading ||
          puedeNavegar === false ||
          total === 0 ||
          (indice ?? -1) < 0 ||
          (indice ?? -1) >= (total ?? 1) - 1
        "
        @click="$emit('ultimo')"
      >
        &gt;|
      </button>
    </div>

    <div
      v-if="puedeGenerarAlbaran || puedeRecuperar || puedeActualizarStock"
      class="toolbar-group"
    >
      <button
        v-if="puedeGenerarAlbaran"
        type="button"
        class="tool-btn tool-btn-albaran"
        :disabled="loading"
        :title="generarAlbaranTitle"
        @click="$emit('generarAlbaran')"
      >
        <ToolIcon name="ficha" />
        <span>{{ generarAlbaranLabel }}</span>
      </button>
      <button
        v-if="puedeRecuperar"
        type="button"
        class="tool-btn"
        :disabled="loading"
        title="Revertir entradas de stock y permitir modificar (legacy Recuperar)"
        @click="$emit('recuperar')"
      >
        <ToolIcon name="modificar" />
        <span>Recuperar</span>
      </button>
      <button
        v-if="puedeActualizarStock"
        type="button"
        class="tool-btn tool-btn-stock"
        :disabled="loading"
        title="Aplicar entradas de stock y marcar ACTUALIZADO"
        @click="$emit('actualizarStock')"
      >
        <ToolIcon name="guardar" />
        <span>Actualizar</span>
      </button>
    </div>

    <div class="toolbar-group">
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || puedeImprimir === false"
        title="Imprimir (solo tras Finalizar: ticket térmico o A4 según tipo)"
        @click="$emit('imprimir')"
      >
        <ToolIcon name="listado" />
        <span>Imprimir</span>
      </button>
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || puedeFinalizar === false"
        :title="
          esTicketCerrado
            ? 'Pasar ticket a factura'
            : 'Finalizar: tipificar cuando el albaran tenga lineas'
        "
        @click="$emit('finalizar')"
      >
        <ToolIcon name="guardar" />
        <span>{{ esTicketCerrado ? 'A factura' : 'Finalizar' }}</span>
      </button>
      <button
        type="button"
        class="tool-btn"
        :disabled="loading || !puedeAbonar"
        title="Abono parcial por líneas (albarán cerrado, ticket o factura de contado)"
        @click="$emit('abonar')"
      >
        <ToolIcon name="aviso" />
        <span>Abono</span>
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
  gap: 0.75rem;
  align-items: center;
  justify-content: space-between;
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
.tool-btn-stock {
  border-color: #b45309;
  background: #fef3c7;
}
.tool-btn-albaran {
  border-color: #b45309;
  background: #fef3c7;
  font-weight: 600;
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
