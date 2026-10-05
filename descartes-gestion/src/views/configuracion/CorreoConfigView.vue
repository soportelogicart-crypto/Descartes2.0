<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const form = reactive({
  activo: true,
  servidor: '',
  puerto: 25,
  ssl: false,
  startTls: false,
  usuarioSmtp: '',
  nombreRemitente: '',
  emailRemitente: '',
  copia: '',
})
const clave = ref('')
const claveConfigurada = ref(false)
const cargando = ref(true)
const guardando = ref(false)
const probando = ref(false)
const mensaje = ref('')
const error = ref('')

onMounted(cargar)

async function cargar() {
  cargando.value = true
  error.value = ''
  mensaje.value = ''
  clave.value = ''
  try {
    const { data } = await api.get('/api/auth/correo')
    form.activo = Boolean(data.activo)
    form.servidor = String(data.servidor ?? '')
    form.puerto = Number(data.puerto ?? 25) || 25
    form.ssl = Boolean(data.ssl)
    form.startTls = Boolean(data.startTls)
    form.usuarioSmtp = String(data.usuarioSmtp ?? '')
    form.nombreRemitente = String(data.nombreRemitente ?? '')
    form.emailRemitente = String(data.emailRemitente ?? '')
    form.copia = String(data.copia ?? '')
    claveConfigurada.value = Boolean(data.claveConfigurada)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo leer la configuración del correo')
  } finally {
    cargando.value = false
  }
}

function payloadCorreo() {
  return { ...form, clave: clave.value }
}

async function probar() {
  probando.value = true
  error.value = ''
  mensaje.value = ''
  try {
    const { data } = await api.post('/api/auth/correo/probar', payloadCorreo())
    mensaje.value = String(data.mensaje || 'Conexión correcta.')
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se ha podido enviar el correo de prueba')
  } finally {
    probando.value = false
  }
}

async function guardar() {
  guardando.value = true
  error.value = ''
  mensaje.value = ''
  try {
    const { data } = await api.put('/api/auth/correo', {
      ...form,
      clave: clave.value,
    })
    clave.value = ''
    claveConfigurada.value = Boolean(data.claveConfigurada)
    mensaje.value = 'Correo guardado.'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el correo')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <section class="correo-config">
    <form @submit.prevent="guardar">
      <div class="toolbar">
        <RouterLink to="/configuracion" class="volver">← Configuración</RouterLink>
        <span class="toolbar-titulo">Correo</span>
        <div class="toolbar-spacer"></div>
        <button type="button" class="tool-btn" :disabled="cargando || guardando || probando" @click="probar">
          {{ probando ? 'Comprobando…' : 'Probar envío' }}
        </button>
        <button class="tool-btn primary" type="submit" :disabled="cargando || guardando || probando">
          {{ guardando ? 'Guardando…' : 'Guardar' }}
        </button>
      </div>

      <p class="intro">
        SMTP de
        <strong>{{ auth.usuario?.nombre || auth.usuario?.codigo }}</strong>.
        Con él se envían pedidos, albaranes y facturas. Cada usuario guarda el suyo.
      </p>

      <div class="tab-form">
        <section class="section">
          <h3>Uso</h3>
          <label class="field checkbox">
            <input v-model="form.activo" type="checkbox" :disabled="cargando" />
            <span>Usar esta cuenta al enviar facturas y documentos</span>
          </label>
        </section>

        <section class="section">
          <h3>Servidor</h3>
          <div class="fields cols-4">
            <label class="field span-2">
              <span class="label">Servidor</span>
              <input v-model="form.servidor" maxlength="200" :disabled="cargando" />
            </label>
            <label class="field">
              <span class="label">Puerto</span>
              <input v-model.number="form.puerto" type="number" min="1" max="65535" :disabled="cargando" />
            </label>
          </div>
          <div class="checks">
            <label class="field checkbox">
              <input v-model="form.ssl" type="checkbox" :disabled="cargando" />
              <span>SSL</span>
            </label>
            <label class="field checkbox">
              <input v-model="form.startTls" type="checkbox" :disabled="cargando" />
              <span>STARTTLS</span>
            </label>
          </div>
        </section>

        <section class="section">
          <h3>Cuenta</h3>
          <div class="fields cols-2">
            <label class="field">
              <span class="label">Usuario</span>
              <input v-model="form.usuarioSmtp" maxlength="200" autocomplete="off" :disabled="cargando" />
            </label>
            <label class="field">
              <span class="label">Contraseña</span>
              <input
                v-model="clave"
                type="password"
                maxlength="200"
                autocomplete="new-password"
                :disabled="cargando"
                :placeholder="claveConfigurada ? 'Guardada. Vacía para no cambiarla' : ''"
              />
            </label>
            <label class="field">
              <span class="label">Nombre del remitente</span>
              <input v-model="form.nombreRemitente" maxlength="200" :disabled="cargando" />
            </label>
            <label class="field">
              <span class="label">Email del remitente</span>
              <input v-model="form.emailRemitente" type="email" maxlength="200" :disabled="cargando" />
            </label>
            <label class="field span-2">
              <span class="label">Copia (CC)</span>
              <input v-model="form.copia" type="email" maxlength="200" :disabled="cargando" />
            </label>
          </div>
          <p class="nota">
            La contraseña se guarda con el usuario y no se vuelve a mostrar. Si el puerto es 25 y no
            usa SSL ni STARTTLS, deje las dos casillas sin marcar.
          </p>
        </section>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="mensaje" class="ok">{{ mensaje }}</p>
    </form>
  </section>
</template>

<style scoped>
.correo-config {
  max-width: 52rem;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.45rem;
  background: linear-gradient(180deg, #f8fafc 0%, #e5e7eb 100%);
  border: 1px solid #94a3b8;
  border-radius: 4px;
}

.volver {
  color: #2563eb;
  text-decoration: none;
  font-size: 0.78rem;
}

.toolbar-titulo {
  font-weight: 600;
  font-size: 0.9rem;
}

.toolbar-spacer {
  flex: 1;
}

.tool-btn {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
}

.tool-btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.tool-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.intro {
  margin: 0.55rem 0;
  color: #64748b;
  font-size: 0.8rem;
}

.tab-form {
  background: #f8fafc;
  border: 1px solid #c5cdd8;
  padding: 0.65rem;
  display: grid;
  gap: 0.65rem;
}

.section {
  margin: 0;
  padding: 0.45rem 0.55rem 0.55rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.section h3 {
  margin: 0 0 0.4rem;
  font-size: 0.78rem;
  font-weight: 700;
  color: #334155;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 0.25rem;
}

.fields {
  display: grid;
  gap: 0.35rem 0.55rem;
}

.cols-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.cols-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.field {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
  min-width: 0;
}

.checks {
  display: flex;
  gap: 1.1rem;
  margin-top: 0.45rem;
}

.field.checkbox {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
}

.span-2 {
  grid-column: span 2;
}

.label {
  color: #475569;
}

input {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
  background: #fff;
}

input[type='checkbox'] {
  width: auto;
}

input:disabled {
  background: #f1f5f9;
  color: #334155;
}

.nota {
  margin: 0.45rem 0 0;
  color: #64748b;
  font-size: 0.75rem;
}

.error,
.ok {
  margin: 0.45rem 0 0;
  font-size: 0.8rem;
}

.error {
  color: #b91c1c;
}

.ok {
  color: #047857;
}

@media (max-width: 800px) {
  .cols-2,
  .cols-4 {
    grid-template-columns: 1fr;
  }

  .span-2 {
    grid-column: auto;
  }
}
</style>
