<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  DATAFONO_CENTROS,
  getDescartesBridge,
  type DatafonoLocalConfig,
} from '@/bridge/electron'

const defaults: DatafonoLocalConfig = {
  activo: false,
  proveedor: '',
  marca: '',
  modelo: '',
  driver: 'SERMEPA',
  tipo: 'webservice',
  centro: 6,
  demo: false,
  comercio: '',
  clave: '',
  claveConfigurada: false,
  version: '4.1',
  terminal: '',
  puerto: '',
  dllPath: '',
  timeoutMs: 120000,
}

const form = reactive<DatafonoLocalConfig>({ ...defaults })
const claveNueva = ref('')
const cargando = ref(true)
const guardando = ref(false)
const probando = ref(false)
const mensaje = ref('')
const error = ref('')

const esSermepa = computed(() => Number(form.centro) === 6)

watch(
  () => form.centro,
  (centro) => {
    const meta = DATAFONO_CENTROS.find((c) => c.value === Number(centro))
    if (meta) form.driver = meta.driver
    form.tipo = Number(centro) === 6 ? 'webservice' : 'dll'
  }
)

onMounted(cargar)

async function cargar() {
  cargando.value = true
  error.value = ''
  claveNueva.value = ''
  try {
    const bridge = getDescartesBridge()
    if (!bridge) {
      error.value = 'La configuración local del datáfono solo está disponible en Descartes Electron.'
      return
    }
    const config = await bridge.getEquipoConfig()
    Object.assign(form, defaults, config.datafono ?? {}, { clave: '' })
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'No se pudo leer la configuración'
  } finally {
    cargando.value = false
  }
}

function validar(): string {
  if (!form.activo) return ''
  if (![0, 1, 2, 3, 4, 6].includes(Number(form.centro))) {
    return 'Seleccione el tipo de comunicación'
  }
  if (esSermepa.value && !form.comercio.trim()) {
    return 'Indique el número de comercio'
  }
  if (esSermepa.value && !form.claveConfigurada && !claveNueva.value.trim()) {
    return 'Indique la clave del TPV'
  }
  if (!esSermepa.value && !form.dllPath.trim()) {
    return 'Indique la ruta de la DLL de comunicación'
  }
  return ''
}

function payloadGuardar(): DatafonoLocalConfig {
  return {
    ...form,
    proveedor: form.proveedor.trim(),
    marca: form.marca.trim(),
    modelo: form.modelo.trim(),
    driver: form.driver.trim().toUpperCase(),
    comercio: form.comercio.trim(),
    clave: claveNueva.value.trim(),
    version: form.version.trim(),
    terminal: form.terminal.trim(),
    puerto: form.puerto.trim(),
    dllPath: form.dllPath.trim(),
    timeoutMs: Math.round(Number(form.timeoutMs) || 120000),
  }
}

async function guardar() {
  error.value = validar()
  mensaje.value = ''
  if (error.value) return
  const bridge = getDescartesBridge()
  if (!bridge) return
  guardando.value = true
  try {
    const config = await bridge.setDatafonoConfig(payloadGuardar())
    Object.assign(form, defaults, config.datafono ?? {}, { clave: '' })
    claveNueva.value = ''
    mensaje.value = 'Configuración del datáfono guardada en este equipo.'
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'No se pudo guardar la configuración'
  } finally {
    guardando.value = false
  }
}

async function probar() {
  error.value = validar()
  mensaje.value = ''
  if (error.value) return
  const bridge = getDescartesBridge()
  if (!bridge) return
  probando.value = true
  try {
    const result = await bridge.paymentTerminalStatus({
      driver: form.driver,
      terminal: form.terminal,
      configuracion: payloadGuardar(),
    })
    if (!result.ok) {
      error.value = result.message || 'El datáfono no está disponible'
      return
    }
    mensaje.value = result.message || 'Datáfono disponible'
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'No se pudo comprobar el datáfono'
  } finally {
    probando.value = false
  }
}
</script>

<template>
  <section class="datafono-config">
    <RouterLink to="/configuracion" class="volver">← Configuración</RouterLink>
    <h2>Datáfono integrado</h2>
    <p class="intro">
      Datos de este PC, equivalentes al INI legacy (<code>Tef*</code>). Se guardan en el JSON del
      puesto. La clave no se muestra al volver a abrir la pantalla.
    </p>

    <p v-if="cargando">Cargando…</p>
    <form v-else class="panel" @submit.prevent="guardar">
      <label class="check">
        <input v-model="form.activo" type="checkbox" />
        <span>Activar cobro mediante datáfono integrado</span>
      </label>
      <label class="check suave">
        <input v-model="form.demo" type="checkbox" />
        <span>Modo demo (TefDemo): no realiza el cargo real</span>
      </label>

      <h3>Comunicación</h3>
      <div class="grid">
        <label>
          <span>Tipo de comunicación (TefCentro)</span>
          <select v-model.number="form.centro">
            <option v-for="c in DATAFONO_CENTROS" :key="c.value" :value="c.value">
              {{ c.label }}
            </option>
          </select>
        </label>
        <label>
          <span>Conector</span>
          <input :value="form.driver" readonly />
          <small>Se asigna según TefCentro.</small>
        </label>
        <label>
          <span>Versión (TefVersion)</span>
          <input v-model="form.version" maxlength="20" placeholder="4.1" />
        </label>
        <label>
          <span>Terminal TPV-PC (TefTerminal)</span>
          <input v-model="form.terminal" maxlength="100" placeholder="4" />
        </label>
        <label>
          <span>Tiempo máximo de respuesta</span>
          <select v-model.number="form.timeoutMs">
            <option :value="30000">30 segundos</option>
            <option :value="60000">60 segundos</option>
            <option :value="120000">120 segundos</option>
            <option :value="180000">180 segundos</option>
          </select>
        </label>
      </div>

      <template v-if="esSermepa">
        <h3>Sermepa / Redsys TPV-PC</h3>
        <div class="grid">
          <label>
            <span>Número de comercio (TefComercio)</span>
            <input v-model="form.comercio" maxlength="50" placeholder="000341735" />
          </label>
          <label>
            <span>Clave del TPV (TefClave)</span>
            <input
              v-model="claveNueva"
              type="password"
              maxlength="200"
              autocomplete="new-password"
              :placeholder="
                form.claveConfigurada ? 'Clave guardada. Deje vacío para no cambiarla' : ''
              "
            />
            <small v-if="form.claveConfigurada">Hay una clave guardada en este equipo.</small>
          </label>
        </div>
      </template>

      <template v-else>
        <h3>DLL de comunicación</h3>
        <div class="grid">
          <label class="ancho">
            <span>Ruta de la DLL</span>
            <input v-model="form.dllPath" maxlength="500" placeholder="C:\...\comunicacion.dll" />
          </label>
        </div>
      </template>

      <h3>Pinpad físico</h3>
      <div class="grid">
        <label>
          <span>Proveedor</span>
          <input v-model="form.proveedor" maxlength="100" placeholder="Banco o integrador" />
        </label>
        <label>
          <span>Marca</span>
          <input v-model="form.marca" maxlength="100" placeholder="Ingenico" />
        </label>
        <label>
          <span>Modelo</span>
          <input v-model="form.modelo" maxlength="100" placeholder="i3370 / i3380" />
        </label>
        <label class="ancho">
          <span>Puerto (TefPuerto)</span>
          <input
            v-model="form.puerto"
            maxlength="120"
            placeholder="COM13:,19200,N,8,1  ó  USB,2816,25856,2,2"
          />
          <small>Serie i3370 o USB i3380, como en el INI legacy.</small>
        </label>
      </div>

      <p class="nota">
        La clave se guarda solo en el JSON de este PC y no se muestra al recargar. Las direcciones
        de producción y pruebas de Redsys son internas y se eligen automáticamente con el modo demo.
      </p>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="mensaje" class="ok">{{ mensaje }}</p>

      <div class="acciones">
        <button type="button" :disabled="guardando || probando || !form.activo" @click="probar">
          {{ probando ? 'Comprobando…' : 'Comprobar conexión' }}
        </button>
        <button class="principal" type="submit" :disabled="guardando || probando">
          {{ guardando ? 'Guardando…' : 'Guardar' }}
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.datafono-config { max-width: 58rem; }
.volver { color: #2563eb; text-decoration: none; font-size: .85rem; }
h2 { margin: .6rem 0 .25rem; }
h3 { margin: 1.2rem 0 .6rem; font-size: .95rem; color: #0f172a; }
.intro { margin: 0 0 1rem; color: #64748b; }
.intro code { background: #f1f5f9; padding: .05rem .3rem; border-radius: 4px; }
.panel { padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; }
.check { display: flex; gap: .55rem; align-items: center; margin-bottom: .6rem; font-weight: 700; }
.check.suave { font-weight: 500; color: #334155; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: .85rem; }
label span { font-weight: 600; color: #334155; }
input, select { padding: .5rem .6rem; border: 1px solid #cbd5e1; border-radius: 7px; }
input[readonly] { background: #f8fafc; color: #475569; }
small { color: #64748b; }
.ancho { grid-column: 1 / -1; }
.nota { margin-top: 1rem; padding: .7rem; background: #f8fafc; color: #64748b; font-size: .8rem; border-radius: 7px; }
.error { color: #b91c1c; }
.ok { color: #047857; }
.acciones { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1rem; }
button { padding: .5rem .85rem; border: 1px solid #94a3b8; border-radius: 7px; background: #fff; cursor: pointer; }
button.principal { color: #fff; border-color: #1d4ed8; background: #2563eb; }
button:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width: 700px) { .grid { grid-template-columns: 1fr; } .ancho { grid-column: auto; } }
</style>
