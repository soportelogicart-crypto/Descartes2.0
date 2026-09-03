<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  configurarInstalacion,
  getInstalacionEstado,
  probarInstalacion,
  type InstalacionEstado,
  type InstalacionDiagnostico,
  type TipoConexionBd,
} from '@/api/instalacion'
import { extractApiError } from '@/composables/extractApiError'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = reactive({
  tipo: 'local' as TipoConexionBd,
  server: 'localhost',
  database: '',
  user: '',
  password: '',
  trustCert: true,
})

const estado = ref<InstalacionEstado | null>(null)
const completado = ref(false)
const credenciales = ref<{ usuario: string; password: string } | null>(null)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)
const probando = ref(false)
const guardando = ref(false)

type FeedbackTipo = 'ok' | 'error' | 'info' | 'loading' | null
const feedbackTipo = ref<FeedbackTipo>(null)
const feedbackTexto = ref<string | null>(null)

const diagnostico = ref<InstalacionDiagnostico | null>(null)
const mostrarDiagnostico = ref(false)

const esPrimeraVez = computed(() => estado.value?.primeraVez ?? !estado.value?.configurado)
const diagnosticoActual = computed(() => diagnostico.value ?? estado.value?.diagnostico ?? null)
const necesitaActualizarEsquema = computed(
  () => estado.value?.conexionOk === true && estado.value?.esquemaOk === false
)
const tituloServidor = computed(() =>
  form.tipo === 'nube' ? 'URL del servidor SQL' : 'Servidor SQL (local / red)'
)
const ayudaServidor = computed(() =>
  form.tipo === 'nube'
    ? 'Ej: mi-empresa.database.windows.net (Azure SQL) o la URL que le haya dado su proveedor.'
    : 'Ej: wslogicartbd, localhost, .\\SQLEXPRESS o 192.168.x.x'
)

function mostrarFeedback(tipo: FeedbackTipo, texto: string | null) {
  feedbackTipo.value = tipo
  feedbackTexto.value = texto
}

function validarFormulario(): string | null {
  if (!form.server.trim()) return 'Indique el servidor o la URL'
  if (!form.database.trim()) return 'Indique el nombre de la base de datos'
  if (!form.user.trim()) return 'Indique el usuario de SQL Server'
  if (!form.password) return 'Indique la contrasena de SQL Server'
  return null
}

async function cargarEstado() {
  error.value = null
  try {
    estado.value = await getInstalacionEstado()
    diagnostico.value = estado.value.diagnostico ?? null
    if (estado.value.tipo) form.tipo = estado.value.tipo
    if (estado.value.server) form.server = estado.value.server
    if (estado.value.database) form.database = estado.value.database
    if (estado.value.user) form.user = estado.value.user
  } catch {
    error.value = 'No se pudo contactar con la API. Compruebe que Apache/XAMPP esta en marcha.'
    mostrarFeedback('error', error.value)
  }
}

async function onProbar() {
  mensaje.value = null
  error.value = null

  const validacion = validarFormulario()
  if (validacion) {
    error.value = validacion
    mostrarFeedback('error', validacion)
    return
  }

  probando.value = true
  mostrarFeedback('loading', 'Comprobando conexion y guardando configuracion…')
  try {
    const res = await probarInstalacion(form)
    if (res.ok) {
      diagnostico.value = (res as { diagnostico?: InstalacionDiagnostico }).diagnostico ?? null
      mostrarDiagnostico.value = true
      mostrarFeedback('ok', 'Conexion correcta y guardada.')
      await cargarEstado()
    } else {
      error.value = res.mensaje || 'No se pudo conectar'
      mostrarFeedback('error', error.value)
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo comprobar la conexion.')
    mostrarFeedback('error', error.value)
  } finally {
    probando.value = false
  }
}

async function onGuardar() {
  const validacion = validarFormulario()
  if (validacion) {
    error.value = validacion
    mostrarFeedback('error', validacion)
    return
  }

  guardando.value = true
  mensaje.value = null
  error.value = null
  mostrarFeedback('loading', 'Conectando, actualizando tablas y preparando usuario administrador…')
  try {
    const res = await configurarInstalacion(form)
    estado.value = res
    diagnostico.value = res.diagnostico ?? null
    mostrarDiagnostico.value = true
    completado.value = !res.requiereAccion && (res.diagnostico?.tieneRol ?? true) && (res.diagnostico?.tieneBaja ?? true)
    credenciales.value = res.acceso ?? { usuario: 'ADM', password: 'admin123' }
    mensaje.value = res.mensaje ?? 'Instalacion completada'
    mostrarFeedback('ok', 'Base de datos preparada.')
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo completar la instalacion')
    mostrarFeedback('error', error.value)
  } finally {
    guardando.value = false
  }
}

async function irAlLogin() {
  try {
    await auth.logout()
  } catch {
    auth.usuario = null
    auth.permisos = {}
    auth.cargado = false
  }
  const loginUrl = router.resolve({ name: 'login' }).href
  window.location.assign(loginUrl)
}

onMounted(() => {
  void cargarEstado()
})
</script>

<template>
  <div class="instalacion">
    <div v-if="completado" class="card card-exito">
      <h1>¡Listo!</h1>
      <p class="intro">Entre con ADM / admin123.</p>
      <details v-if="diagnosticoActual" class="diagnostico">
        <summary>Detalle de conexion</summary>
        <p>{{ diagnosticoActual.servidorSql }} / {{ diagnosticoActual.baseDatos }}</p>
      </details>
      <div class="credenciales">
        <p>Use estas credenciales para entrar:</p>
        <dl>
          <dt>Usuario</dt>
          <dd>{{ credenciales?.usuario ?? 'ADM' }}</dd>
          <dt>Contrasena</dt>
          <dd>{{ credenciales?.password ?? 'admin123' }}</dd>
        </dl>
      </div>
      <button type="button" @click="irAlLogin">Ir al login</button>
    </div>

    <form v-else class="card" @submit.prevent="onGuardar">
      <div class="card-scroll">
        <h1>{{ esPrimeraVez ? 'Bienvenido a Descartes' : 'Conexion a la base de datos' }}</h1>
        <p class="intro">
          Paso 1: comprobar. Paso 2: conectar y preparar tablas (usuario ADM).
        </p>

        <fieldset class="tipo-conexion">
          <legend>Tipo de conexion</legend>
          <label class="radio">
            <input v-model="form.tipo" type="radio" value="local" />
            Servidor local / red
          </label>
          <label class="radio">
            <input v-model="form.tipo" type="radio" value="nube" />
            Base de datos en la nube
          </label>
        </fieldset>

        <label>
          {{ tituloServidor }}
          <input
            v-model="form.server"
            autocomplete="off"
            :placeholder="form.tipo === 'nube' ? 'mi-servidor.database.windows.net' : 'wslogicartbd'"
          />
          <span class="hint">{{ ayudaServidor }}</span>
        </label>
        <label>
          Nombre de la base de datos
          <input v-model="form.database" autocomplete="off" placeholder="Ej: LOGIA" />
        </label>
        <label>
          Usuario SQL
          <input v-model="form.user" autocomplete="off" placeholder="Ej: sa" />
        </label>
        <label>
          Contrasena SQL
          <input v-model="form.password" type="password" autocomplete="new-password" />
        </label>
        <label class="checkbox">
          <input v-model="form.trustCert" type="checkbox" />
          Confiar en certificado del servidor
        </label>

        <details
          v-if="mostrarDiagnostico && diagnosticoActual"
          class="diagnostico"
          :open="necesitaActualizarEsquema"
        >
          <summary>Detalle de conexion</summary>
          <p>{{ diagnosticoActual.servidorSql }} / {{ diagnosticoActual.baseDatos }}</p>
          <p v-if="diagnosticoActual.tieneRol && diagnosticoActual.tieneBaja" class="diag-ok">
            Usuarios.Rol y Usuarios.Baja: OK
          </p>
          <p v-else class="diag-alerta">Faltan columnas Rol o Baja en Usuarios.</p>
        </details>
      </div>

      <div
        v-if="feedbackTexto || necesitaActualizarEsquema"
        class="feedback"
        :class="{
          'feedback-ok': feedbackTipo === 'ok',
          'feedback-error': feedbackTipo === 'error',
          'feedback-info': feedbackTipo === 'loading',
          'feedback-aviso': necesitaActualizarEsquema && feedbackTipo !== 'error',
        }"
        role="status"
        aria-live="polite"
      >
        {{
          feedbackTexto
            ?? (necesitaActualizarEsquema ? 'Faltan cambios de tablas. Pulse «Conectar y preparar».' : '')
        }}
      </div>

      <div class="acciones">
        <button type="button" class="secundario" :disabled="probando || guardando" @click="onProbar">
          {{ probando ? 'Comprobando…' : 'Comprobar' }}
        </button>
        <button type="submit" :disabled="guardando || probando">
          {{ guardando ? 'Preparando…' : 'Conectar y preparar' }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.instalacion {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: #f1f5f9;
}

.card {
  width: min(100%, 480px);
  max-height: calc(100vh - 2rem);
  background: #fff;
  border-radius: 12px;
  padding: 0;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.card-scroll {
  padding: 1.25rem 1.25rem 0.5rem;
  overflow-y: auto;
  display: grid;
  gap: 0.65rem;
}

.card-exito {
  padding: 1.5rem;
  text-align: center;
  display: grid;
  gap: 0.75rem;
  overflow-y: auto;
}

h1 {
  margin: 0;
  font-size: 1.25rem;
}

.intro {
  margin: 0;
  font-size: 0.88rem;
  color: #64748b;
  line-height: 1.45;
}


.diagnostico {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 0.5rem 0.65rem;
  font-size: 0.82rem;
  line-height: 1.4;
}

.diagnostico summary {
  cursor: pointer;
  font-weight: 600;
  color: #475569;
}

.diagnostico p {
  margin: 0.35rem 0 0;
}

.diag-ok {
  color: #166534;
}

.diag-alerta {
  color: #b45309;
}

.feedback {
  flex-shrink: 0;
  margin: 0 1.25rem;
  padding: 0.5rem 0.65rem;
  border-radius: 8px;
  font-size: 0.85rem;
  line-height: 1.35;
  border: 1px solid transparent;
}

.feedback-aviso {
  background: #fffbeb;
  border-color: #fcd34d;
  color: #92400e;
}

.feedback-ok {
  background: #ecfdf5;
  border-color: #6ee7b7;
  color: #065f46;
}

.feedback-error {
  background: #fef2f2;
  border-color: #fca5a5;
  color: #991b1b;
}

.feedback-info {
  background: #eff6ff;
  border-color: #93c5fd;
  color: #1e40af;
}

.tipo-conexion {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 0.75rem;
  display: grid;
  gap: 0.5rem;
}

.tipo-conexion legend {
  padding: 0 0.25rem;
  font-size: 0.85rem;
  color: #475569;
}

.radio {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

label {
  display: grid;
  gap: 0.25rem;
  font-size: 0.9rem;
}

.hint {
  font-size: 0.78rem;
  color: #64748b;
}

label.checkbox {
  grid-template-columns: auto 1fr;
  align-items: center;
  gap: 0.5rem;
}

input[type='text'],
input[type='password'] {
  padding: 0.5rem 0.65rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
}

.credenciales {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  padding: 1rem;
  text-align: left;
}

.credenciales dl {
  margin: 0.5rem 0 0;
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.35rem 1rem;
}

.credenciales dt {
  font-weight: 600;
  color: #166534;
}

.credenciales dd {
  margin: 0;
  font-family: ui-monospace, monospace;
}

.acciones {
  flex-shrink: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.75rem 1.25rem 1.25rem;
  border-top: 1px solid #e2e8f0;
  background: #fff;
}

.acciones button {
  flex: 1 1 8rem;
}

button {
  padding: 0.55rem 0.9rem;
  border: none;
  border-radius: 8px;
  background: #2563eb;
  color: #fff;
  cursor: pointer;
}

button.secundario {
  background: #e2e8f0;
  color: #0f172a;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
