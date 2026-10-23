<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import { entidades } from '@/config/entidades'
import { usePermisos } from '@/composables/usePermisos'
import DecimalInput from '@/components/common/DecimalInput.vue'

const config = entidades.empresas
const { puede } = usePermisos()

const form = reactive<Record<string, unknown>>({
  nif: '',
  razonSocial: '',
  direccion: '',
  poblacion: '',
  codigoPostal: '',
  provincia: '',
  pais: '',
  email: '',
  divisa: '',
  regimenFiscal: 'comun',
  ticketSITerritorio: '',
  ticketSICertificado: '',
  facturaLaCentral: false,
  contadores: {
    ultFactura: 0,
    ultTicket: 0,
    ultAlbaranVen: 0,
  },
})

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)

const puedeEditar = computed(() => puede('empresas', 'editar'))
const muestraTicketBai = computed(() => form.regimenFiscal === 'ticketbai')

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/api/mantenimiento/empresas')
    Object.assign(form, data)
    if (!form.contadores) {
      form.contadores = { ultFactura: 0, ultTicket: 0, ultAlbaranVen: 0 }
    }
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    error.value = err.response?.data?.error ?? 'No se pudieron cargar los datos fiscales'
  } finally {
    loading.value = false
  }
}

async function guardar() {
  if (!puedeEditar.value) return
  saving.value = true
  mensaje.value = null
  error.value = null
  try {
    const payload = { ...form }
    if (payload.regimenFiscal === 'comun') {
      payload.ticketSITerritorio = null
    }
    const { data } = await api.put('/api/mantenimiento/empresas', payload)
    Object.assign(form, data)
    mensaje.value = 'Datos fiscales guardados correctamente'
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    error.value = err.response?.data?.error ?? 'Error al guardar'
  } finally {
    saving.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <section class="empresa">
    <header>
      <h2>{{ config.titulo }}</h2>
      <p class="hint">Datos fiscales del cliente (fila central). No admite alta ni baja.</p>
    </header>

    <p v-if="loading">Cargando...</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>
    <p v-if="error" class="err">{{ error }}</p>

    <form v-if="!loading" class="form" @submit.prevent="guardar">
      <div v-for="campo in config.campos" :key="campo.key" class="field">
        <template v-if="campo.key !== 'ticketSITerritorio' || muestraTicketBai">
          <label :for="campo.key">{{ campo.label }}</label>
          <select
            v-if="campo.type === 'select'"
            :id="campo.key"
            v-model="form[campo.key]"
            :disabled="!puedeEditar"
          >
            <option v-for="opt in campo.options" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
          <input
            v-else-if="campo.type === 'checkbox'"
            :id="campo.key"
            v-model="form[campo.key]"
            type="checkbox"
            :disabled="!puedeEditar"
          />
          <input
            v-else
            :id="campo.key"
            v-model="form[campo.key]"
            :type="campo.type ?? 'text'"
            :required="campo.required"
            :readonly="campo.readOnly || !puedeEditar"
          />
        </template>
      </div>

      <fieldset class="contadores">
        <legend>Contadores de documentos</legend>
        <label>
          Ultima factura
          <DecimalInput
            :model-value="Number((form.contadores as Record<string, number>).ultFactura) || 0"
            :empty-as-null="false"
            :integer="true"
            :readonly="!puedeEditar"
            @update:model-value="(form.contadores as Record<string, number>).ultFactura = $event ?? 0"
          />
        </label>
        <label>
          Ultimo ticket
          <DecimalInput
            :model-value="Number((form.contadores as Record<string, number>).ultTicket) || 0"
            :empty-as-null="false"
            :integer="true"
            :readonly="!puedeEditar"
            @update:model-value="(form.contadores as Record<string, number>).ultTicket = $event ?? 0"
          />
        </label>
        <label>
          Ultimo albaran venta
          <DecimalInput
            :model-value="Number((form.contadores as Record<string, number>).ultAlbaranVen) || 0"
            :empty-as-null="false"
            :integer="true"
            :readonly="!puedeEditar"
            @update:model-value="(form.contadores as Record<string, number>).ultAlbaranVen = $event ?? 0"
          />
        </label>
      </fieldset>

      <button v-if="puedeEditar" type="submit" :disabled="saving">
        {{ saving ? 'Guardando...' : 'Guardar' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.hint {
  color: #6b7280;
  margin-top: 0;
}

.form {
  background: #fff;
  border-radius: 8px;
  padding: 1rem;
  display: grid;
  gap: 0.75rem;
  max-width: 640px;
}

.field {
  display: grid;
  gap: 0.25rem;
}

input,
select {
  padding: 0.5rem 0.65rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
}

.contadores {
  display: grid;
  gap: 0.5rem;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 0.75rem;
}

button[type='submit'] {
  justify-self: start;
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 0.55rem 1rem;
  font-weight: 600;
}

.ok {
  color: #047857;
}

.err {
  color: #b91c1c;
}
</style>
