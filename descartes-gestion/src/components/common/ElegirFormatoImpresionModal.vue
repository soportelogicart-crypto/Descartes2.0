<script setup lang="ts">
export type FormatoImpresionElegido = 'ticket' | 'a4' | 'albaran'

withDefaults(
  defineProps<{
    open: boolean
    /** Opción principal A4 (Factura, Albarán, Presupuesto). */
    etiquetaA4?: string
    /** Segunda opción (Ticket o Albarán). */
    etiquetaSecundaria?: string
    valorSecundario?: FormatoImpresionElegido
  }>(),
  {
    etiquetaA4: 'Albarán',
    etiquetaSecundaria: 'Ticket',
    valorSecundario: 'ticket',
  }
)

const emit = defineEmits<{
  elegir: [formato: FormatoImpresionElegido]
  cancelar: []
}>()
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="overlay"
      role="dialog"
      aria-modal="true"
      aria-label="Elegir formato de impresión"
      @click.self="emit('cancelar')"
    >
      <div class="modal">
        <h3>Imprimir</h3>
        <p>¿Qué documento quiere imprimir?</p>
        <div class="acciones">
          <button type="button" class="btn" @click="emit('elegir', valorSecundario)">
            {{ etiquetaSecundaria }}
          </button>
          <button type="button" class="btn primary" @click="emit('elegir', 'a4')">
            {{ etiquetaA4 }}
          </button>
          <button type="button" class="btn" @click="emit('cancelar')">Cancelar</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 1400;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}
.modal {
  width: min(22rem, 100%);
  display: flex;
  flex-wrap: wrap;
  flex-direction: column;
  gap: 0.65rem;
  padding: 1rem 1.1rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.2);
}
h3 {
  margin: 0;
  font-size: 1rem;
  color: #0f172a;
}
p {
  margin: 0;
  font-size: 0.88rem;
  color: #334155;
}
.acciones {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.4rem;
}
.btn {
  padding: 0.4rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}
.btn.primary {
  background: #0f172a;
  color: #fff;
  border-color: #0f172a;
}
</style>
