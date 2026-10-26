<script setup lang="ts">
import { computed, ref, watch } from 'vue'

export type AltaClubDatos = {
  telefono: string
  email: string
  fechaNacimiento: string
  consentimientoEmail: boolean
  consentimientoWhatsApp: boolean
}

const props = defineProps<{
  open: boolean
  nombre: string
  telefono: string
  email: string
}>()

const emit = defineEmits<{
  confirmar: [AltaClubDatos]
  omitir: []
}>()

const telefono = ref('')
const email = ref('')
const dia = ref('')
const mes = ref('')
const condiciones = ref(false)
const consentimientoEmail = ref(false)
const consentimientoWhatsApp = ref(false)
const error = ref('')

const fechaNacimiento = computed(() => {
  const d = Number(dia.value)
  const m = Number(mes.value)
  if (!dia.value && !mes.value) return ''
  if (!Number.isInteger(d) || !Number.isInteger(m) || m < 1 || m > 12 || d < 1 || d > 31) return ''
  const dt = new Date(1900, m - 1, d)
  if (dt.getMonth() !== m - 1 || dt.getDate() !== d) return ''
  const dd = String(d).padStart(2, '0')
  const mm = String(m).padStart(2, '0')
  return `1900-${mm}-${dd}`
})

watch(
  () => props.open,
  (abierto) => {
    if (!abierto) return
    telefono.value = props.telefono
    email.value = props.email
    dia.value = ''
    mes.value = ''
    condiciones.value = false
    consentimientoEmail.value = false
    consentimientoWhatsApp.value = false
    error.value = ''
  }
)

function confirmar() {
  error.value = ''
  const movil = telefono.value.trim()
  if (movil.length < 9) {
    error.value = 'Indique el teléfono móvil'
    return
  }
  if ((dia.value || mes.value) && !fechaNacimiento.value) {
    error.value = 'El día y el mes de nacimiento no son válidos'
    return
  }
  if (!condiciones.value) {
    error.value = 'Hay que aceptar las condiciones del club'
    return
  }
  if (consentimientoEmail.value && !email.value.trim()) {
    error.value = 'Indique el email para recibir ofertas'
    return
  }
  emit('confirmar', {
    telefono: movil,
    email: email.value.trim(),
    fechaNacimiento: fechaNacimiento.value,
    consentimientoEmail: consentimientoEmail.value,
    consentimientoWhatsApp: consentimientoWhatsApp.value,
  })
}
</script>

<template>
  <div v-if="open" class="overlay">
    <div class="ventana" role="dialog" aria-modal="true" @mousedown.stop>
      <header class="barra">ALTA CLUB</header>
      <div class="cuerpo">
        <p class="nombre">{{ nombre }}</p>
        <p class="texto">
          El alta es gratis y no obliga a recibir publicidad. El vale de 5 € se usa en una
          compra posterior de al menos 30 €, durante 30 días.
        </p>
        <label>Móvil <input v-model="telefono" type="tel" inputmode="tel" /></label>
        <label>Email (opcional) <input v-model="email" type="email" /></label>
        <div class="cumple">
          <label>Día cumple <input v-model="dia" type="text" inputmode="numeric" maxlength="2" /></label>
          <label>Mes <input v-model="mes" type="text" inputmode="numeric" maxlength="2" /></label>
        </div>
        <label class="check">
          <input v-model="condiciones" type="checkbox" />
          Acepta las condiciones del club
        </label>
        <label class="check">
          <input v-model="consentimientoEmail" type="checkbox" />
          Quiero ofertas por email
        </label>
        <label class="check">
          <input v-model="consentimientoWhatsApp" type="checkbox" />
          Quiero ofertas por WhatsApp
        </label>
        <p v-if="error" class="error">{{ error }}</p>
      </div>
      <footer class="pie">
        <button type="button" class="btn aceptar" @click="confirmar">DAR DE ALTA</button>
        <button type="button" class="btn cancelar" @click="emit('omitir')">AHORA NO</button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
}

.ventana {
  width: min(28rem, 94vw);
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, sans-serif;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: #0f172a;
  color: #f8fafc;
  font-weight: 600;
}

.cuerpo {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 0.85rem;
}

.nombre {
  margin: 0;
  font-weight: 700;
}

.texto {
  margin: 0;
  font-size: 0.85rem;
  line-height: 1.35;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.8rem;
  font-weight: 600;
}

input[type='tel'],
input[type='email'],
input[type='text'] {
  min-height: 2.4rem;
  padding: 0.35rem 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font: inherit;
}

.cumple {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}

.check {
  flex-direction: row;
  align-items: center;
  gap: 0.45rem;
  font-weight: 500;
}

.error {
  margin: 0;
  color: #b91c1c;
  font-size: 0.85rem;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.btn {
  min-height: 3rem;
  border-radius: 10px;
  font-weight: 700;
  cursor: pointer;
}

.aceptar {
  background: #059669;
  border: 1px solid #059669;
  color: #fff;
}

.cancelar {
  background: #e2e8f0;
  border: 1px solid #cbd5e1;
}
</style>
