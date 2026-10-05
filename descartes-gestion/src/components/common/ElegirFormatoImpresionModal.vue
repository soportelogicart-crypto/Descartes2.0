<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

export type FormatoImpresionElegido = 'ticket' | 'a4' | 'albaran'

const props = withDefaults(
  defineProps<{
    open: boolean
    /** Opción principal A4 (Factura, Albarán, Presupuesto). */
    etiquetaA4?: string
    /** Segunda opción (Ticket o Albarán). */
    etiquetaSecundaria?: string
    valorSecundario?: FormatoImpresionElegido
    mostrarSecundaria?: boolean
    emailInicial?: string | null
    procesando?: boolean
    error?: string | null
  }>(),
  {
    etiquetaA4: 'Albarán',
    etiquetaSecundaria: 'Ticket',
    valorSecundario: 'ticket',
    mostrarSecundaria: true,
  }
)

const emit = defineEmits<{
  elegir: [formato: FormatoImpresionElegido]
  email: [email: string]
  cancelar: []
}>()

const mostrandoEmail = ref(false)
const email = ref('')
const inputEmail = ref<HTMLInputElement | null>(null)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    mostrandoEmail.value = false
    email.value = String(props.emailInicial ?? '').trim()
  }
)

async function abrirEmail() {
  mostrandoEmail.value = true
  await nextTick()
  inputEmail.value?.focus()
}

function enviarEmail() {
  const destino = email.value.trim()
  if (!destino || props.procesando) return
  emit('email', destino)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="overlay"
      role="dialog"
      aria-modal="true"
      aria-label="Imprimir o enviar documento"
      @click.self="emit('cancelar')"
    >
      <div class="modal">
        <h3>Imprimir o enviar</h3>
        <p>¿Qué desea hacer con el documento?</p>
        <div v-if="!mostrandoEmail" class="acciones">
          <button
            v-if="mostrarSecundaria"
            type="button"
            class="btn"
            :disabled="procesando"
            @click="emit('elegir', valorSecundario)"
          >
            {{ etiquetaSecundaria }}
          </button>
          <button
            type="button"
            class="btn primary"
            :disabled="procesando"
            @click="emit('elegir', 'a4')"
          >
            {{ etiquetaA4 }}
          </button>
          <button type="button" class="btn email" :disabled="procesando" @click="abrirEmail">
            Enviar por email
          </button>
          <button type="button" class="btn" :disabled="procesando" @click="emit('cancelar')">
            Cancelar
          </button>
        </div>
        <form v-else class="form-email" @submit.prevent="enviarEmail">
          <label>
            Dirección de email
            <input
              ref="inputEmail"
              v-model="email"
              type="email"
              inputmode="email"
              autocomplete="email"
              required
              placeholder="cliente@ejemplo.com"
            />
          </label>
          <div class="acciones">
            <button type="button" class="btn" :disabled="procesando" @click="mostrandoEmail = false">
              Atrás
            </button>
            <button type="submit" class="btn email" :disabled="procesando || !email.trim()">
              {{ procesando ? 'Enviando…' : 'Enviar' }}
            </button>
          </div>
        </form>
        <p v-if="error" class="error">{{ error }}</p>
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
.btn.email {
  background: #dbeafe;
  border-color: #60a5fa;
}
.form-email,
.form-email label {
  display: grid;
  gap: 0.45rem;
  font-size: 0.85rem;
  color: #334155;
}
.form-email input {
  min-height: 2.25rem;
  padding: 0.35rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
}
.error {
  padding: 0.45rem;
  border: 1px solid #fca5a5;
  border-radius: 3px;
  background: #fef2f2;
  color: #991b1b;
}
</style>
