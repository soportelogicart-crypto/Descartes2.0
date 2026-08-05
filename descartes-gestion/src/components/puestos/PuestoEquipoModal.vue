<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { getDescartesBridge, isElectronShell } from '@/bridge/electron'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

const props = defineProps<{
  open: boolean
  obligatorio?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  confirmado: []
}>()

const puestoContexto = usePuestoContextoStore()
const enElectron = computed(() => isElectronShell())
const empresasOpciones = ref<{ value: string; label: string }[]>([])
const puestosOpciones = ref<{ value: string; label: string }[]>([])
const equipoIdInput = ref('')
const hostname = ref('')
const empresaSeleccion = ref('')
const puestoSeleccion = ref('')
const loading = ref(false)
const guardando = ref(false)
const error = ref<string | null>(null)
const avisoRecuperado = ref<string | null>(null)

onMounted(() => cargarOpciones())

watch(
  () => props.open,
  async (abierto) => {
    if (abierto) {
      empresaSeleccion.value = puestoContexto.empresaCodigo ?? ''
      puestoSeleccion.value = puestoContexto.puestoCodigo ?? ''
      error.value = null
      avisoRecuperado.value = null
      if (enElectron.value) {
        const bridge = getDescartesBridge()
        hostname.value = bridge ? await bridge.getHostname() : ''
        equipoIdInput.value = hostname.value
        const local = bridge ? await bridge.getEquipoConfig() : null
        if (local?.configurado) {
          empresaSeleccion.value = local.empresaCodigo ?? ''
          puestoSeleccion.value = local.puestoCodigo ?? ''
        }
      } else {
        equipoIdInput.value = puestoContexto.equipoId ?? ''
      }
      cargarOpciones()
    }
  }
)

async function cargarOpciones() {
  loading.value = true
  error.value = null
  try {
    const [empresasRes, puestosRes] = await Promise.all([
      api.get('/api/mantenimiento/tiendas', { params: { activo: true, conParametros: true, pageSize: 100 } }),
      api.get('/api/mantenimiento/puestos-trabajo', { params: { pageSize: 500 } }),
    ])

    empresasOpciones.value = (empresasRes.data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))

    puestosOpciones.value = (puestosRes.data.items ?? []).map((p: { codigo: string; descripcion: string }) => ({
      value: String(p.codigo).trim(),
      label: `${String(p.codigo).trim()} - ${p.descripcion}`,
    }))

    if (!empresaSeleccion.value && empresasOpciones.value.length === 1) {
      empresaSeleccion.value = empresasOpciones.value[0].value
    }
    if (!puestoSeleccion.value && puestosOpciones.value.length === 1) {
      puestoSeleccion.value = puestosOpciones.value[0].value
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar las opciones')
  } finally {
    loading.value = false
  }
}

async function onEquipoBlur() {
  if (enElectron.value) return
  const id = equipoIdInput.value.trim()
  if (!id) return
  avisoRecuperado.value = null
  const data = await puestoContexto.obtenerDesdeServidor(id)
  if (data) {
    empresaSeleccion.value = data.empresaCodigo
    puestoSeleccion.value = data.puestoCodigo
    equipoIdInput.value = data.equipoId
    avisoRecuperado.value = 'Configuracion recuperada desde el servidor.'
  }
}

async function confirmar() {
  error.value = null
  avisoRecuperado.value = null

  if (!enElectron.value && !equipoIdInput.value.trim()) {
    error.value = 'Indique el identificador de este equipo'
    return
  }
  if (!empresaSeleccion.value) {
    error.value = 'Seleccione la empresa'
    return
  }
  if (!puestoSeleccion.value) {
    error.value = 'Seleccione el puesto'
    return
  }

  guardando.value = true
  try {
    const id = enElectron.value ? hostname.value || equipoIdInput.value : equipoIdInput.value
    await puestoContexto.setEquipo(id, empresaSeleccion.value, puestoSeleccion.value)
    emit('confirmado')
    emit('cerrar')
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar la configuracion')
  } finally {
    guardando.value = false
  }
}

function onCancelar() {
  if (props.obligatorio) return
  emit('cerrar')
}
</script>

<template>
  <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="onCancelar">
    <div class="modal">
      <header class="modal-header">
        <h3>Configuracion de este equipo</h3>
      </header>

      <p v-if="enElectron" class="hint">
        Configuracion de instalacion (una vez por PC). Se guarda en el disco de esta maquina
        (<code>{{ hostname || '...' }}</code>). El cajero no necesita conocerla; limpia la cache del navegador
        no la borra.
      </p>
      <p v-else class="hint">
        Indique un identificador fijo para este PC (ej. CAIXA21), la empresa y el puesto. Se guarda en el servidor;
        si limpia la cache del navegador, vuelva a escribir el mismo identificador para recuperarla.
      </p>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="avisoRecuperado" class="ok">{{ avisoRecuperado }}</p>

      <label v-if="!enElectron" class="field">
        <span>Identificador de este equipo</span>
        <input
          v-model="equipoIdInput"
          type="text"
          maxlength="50"
          placeholder="Ej. CAIXA21"
          :disabled="loading || guardando"
          @blur="onEquipoBlur"
          @keyup.enter="onEquipoBlur"
        />
      </label>
      <div v-else class="field">
        <span>PC (automatico)</span>
        <p class="hostname">{{ hostname || 'Detectando...' }}</p>
      </div>

      <label class="field">
        <span>Empresa</span>
        <select v-model="empresaSeleccion" :disabled="loading || guardando">
          <option value="">— Seleccione —</option>
          <option v-for="opt in empresasOpciones" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
      </label>
      <label class="field">
        <span>Puesto</span>
        <select v-model="puestoSeleccion" :disabled="loading || guardando">
          <option value="">— Seleccione —</option>
          <option v-for="opt in puestosOpciones" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
      </label>
      <footer class="modal-footer">
        <button v-if="!obligatorio" type="button" class="btn-cancel" :disabled="guardando" @click="onCancelar">
          Cancelar
        </button>
        <button
          type="button"
          class="btn-confirm"
          :disabled="
            loading ||
            guardando ||
            (!enElectron && !equipoIdInput.trim()) ||
            !empresaSeleccion ||
            !puestoSeleccion
          "
          @click="confirmar"
        >
          {{ guardando ? 'Guardando...' : 'Guardar' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  z-index: 60;
  padding: 1rem;
}

.modal {
  width: min(440px, 100%);
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
  padding: 0 0 1rem;
}

.modal-header {
  padding: 0.85rem 1rem 0;
}

.modal-header h3 {
  margin: 0;
  font-size: 1rem;
}

.hint {
  margin: 0.75rem 1rem;
  color: #64748b;
  font-size: 0.85rem;
  line-height: 1.45;
}

.hint code {
  font-size: 0.8rem;
  background: #f1f5f9;
  padding: 0.05rem 0.3rem;
  border-radius: 4px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0 1rem 0.75rem;
  font-size: 0.85rem;
}

.field input,
.field select {
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
}

.hostname {
  margin: 0;
  padding: 0.45rem 0.55rem;
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-weight: 600;
}

.error {
  margin: 0 1rem 0.75rem;
  color: #b91c1c;
  font-size: 0.85rem;
}

.ok {
  margin: 0 1rem 0.75rem;
  color: #047857;
  font-size: 0.85rem;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1rem 0;
}

.btn-cancel,
.btn-confirm {
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  border: 1px solid #cbd5e1;
  background: #fff;
  cursor: pointer;
  font-size: 0.85rem;
}

.btn-confirm {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.btn-confirm:disabled,
.btn-cancel:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
</style>
