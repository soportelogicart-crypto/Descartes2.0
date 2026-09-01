<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  documento: string
  emailInicial?: string | null
  procesando?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  imprimir: []
  email: [string]
  omitir: []
}>()

const email = ref('')
const mostrandoEmail = ref(false)
const inputEmail = ref<HTMLInputElement | null>(null)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    email.value = String(props.emailInicial ?? '').trim()
    mostrandoEmail.value = false
  }
)

async function abrirEmail() {
  mostrandoEmail.value = true
  await nextTick()
  inputEmail.value?.focus()
}

function enviar() {
  if (!email.value.trim() || props.procesando) return
  emit('email', email.value.trim())
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay">
      <section class="ventana" role="dialog" aria-modal="true" aria-labelledby="post-venta-titulo">
        <header id="post-venta-titulo">VENTA FINALIZADA</header>
        <div class="cuerpo">
          <strong>{{ documento }}</strong>
          <p>¿Qué desea hacer con el documento?</p>

          <div v-if="!mostrandoEmail" class="acciones">
            <button type="button" class="imprimir" :disabled="procesando" @click="emit('imprimir')">
              IMPRIMIR
            </button>
            <button type="button" class="email" :disabled="procesando" @click="abrirEmail">
              ENVIAR POR EMAIL
            </button>
            <button type="button" :disabled="procesando" @click="emit('omitir')">
              NO IMPRIMIR
            </button>
          </div>

          <form v-else class="form-email" @submit.prevent="enviar">
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
            <div class="acciones-email">
              <button type="button" :disabled="procesando" @click="mostrandoEmail = false">
                ATRÁS
              </button>
              <button type="submit" class="email" :disabled="procesando || !email.trim()">
                {{ procesando ? 'ENVIANDO…' : 'ENVIAR' }}
              </button>
            </div>
          </form>

          <p v-if="error" class="error">{{ error }}</p>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 5200;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 60%);
}

.ventana {
  width: min(32rem, 96vw);
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  color: #000;
  font-family: 'Segoe UI', Tahoma, sans-serif;
}

header {
  padding: 0.45rem 0.7rem;
  background: linear-gradient(#00309c, #000060);
  color: #fff;
  font-weight: 700;
}

.cuerpo {
  display: grid;
  gap: 0.75rem;
  padding: 1rem;
  text-align: center;
}

.cuerpo p {
  margin: 0;
}

.acciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}

.acciones button:last-child {
  grid-column: 1 / -1;
}

button {
  min-height: 3.5rem;
  padding: 0.5rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  border-radius: 0;
  font: inherit;
  font-weight: 700;
}

button:active:not(:disabled) {
  border-style: inset;
}

.imprimir {
  background: #c8e0c8;
}

.email {
  background: #c8d8f0;
}

.form-email,
label {
  display: grid;
  gap: 0.5rem;
  text-align: left;
}

input {
  min-height: 3rem;
  padding: 0.5rem;
  border: 2px inset #f5f5f5;
  border-radius: 0;
  font: inherit;
}

.acciones-email {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}

.error {
  padding: 0.5rem;
  background: #ffd7d7;
  border: 1px solid #a00000;
  color: #800000;
  text-align: left;
}
</style>
