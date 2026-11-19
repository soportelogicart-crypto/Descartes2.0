<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { getDescartesBridge } from '@/bridge/electron'
import {
  guardarRutaCarpetaCompartida,
  obtenerRutaCarpetaCompartida,
} from '@/api/carpetaCompartida'
import { useCarpetaCompartidaStore } from '@/stores/carpetaCompartida'
import CarpetaCompartidaExplorador from '@/components/carpeta/CarpetaCompartidaExplorador.vue'

const bridge = getDescartesBridge()
const carpeta = bridge?.carpetaCompartida
const carpetaStore = useCarpetaCompartidaStore()

const cargando = ref(true)
const guardando = ref(false)
const probando = ref(false)
const mensaje = ref('')
const error = ref('')

/** Ruta guardada en la instalación (la que usan todos los PCs). */
const rutaGuardada = ref('')
/** Ruta que se está editando en el formulario. */
const rutaEdicion = ref('')
/** Última ruta que ha pasado la prueba de acceso. */
const rutaProbada = ref('')

const probada = computed(
  () => rutaEdicion.value.trim() !== '' && rutaEdicion.value.trim() === rutaProbada.value
)

onMounted(cargar)

function mensajeDeError(e: unknown): string {
  const data = (e as { response?: { data?: { error?: string } } })?.response?.data
  if (data?.error) return data.error
  return e instanceof Error ? e.message : 'Error inesperado'
}

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const ruta = await obtenerRutaCarpetaCompartida()
    rutaGuardada.value = ruta
    rutaEdicion.value = ruta
    // Lo guardado ya pasó la prueba cuando se guardó.
    rutaProbada.value = ruta
    carpetaStore.fijar(ruta)
  } catch (e) {
    error.value = mensajeDeError(e)
  } finally {
    cargando.value = false
  }
}

async function probar() {
  error.value = ''
  mensaje.value = ''
  const ruta = rutaEdicion.value.trim()
  if (!carpeta || !ruta) return
  probando.value = true
  try {
    const res = await carpeta.probar(ruta)
    if (res.ok) {
      rutaProbada.value = ruta
      mensaje.value = res.message ?? 'Carpeta accesible.'
    } else {
      rutaProbada.value = ''
      error.value = res.message ?? 'La carpeta no es accesible'
    }
  } finally {
    probando.value = false
  }
}

async function guardar() {
  error.value = ''
  mensaje.value = ''
  const ruta = rutaEdicion.value.trim()
  if (ruta && !probada.value) {
    error.value = 'Pruebe la carpeta antes de guardar.'
    return
  }
  guardando.value = true
  try {
    const nueva = await guardarRutaCarpetaCompartida(ruta)
    rutaGuardada.value = nueva
    rutaEdicion.value = nueva
    rutaProbada.value = nueva
    carpetaStore.fijar(nueva)
    mensaje.value = nueva
      ? 'Ruta guardada para toda la instalación.'
      : 'Carpeta compartida quitada de la instalación.'
  } catch (e) {
    error.value = mensajeDeError(e)
  } finally {
    guardando.value = false
  }
}

async function elegirLocal() {
  error.value = ''
  mensaje.value = ''
  if (!carpeta) return
  const res = await carpeta.elegirLocal()
  if (!res.ok) {
    error.value = res.message ?? 'No se pudo elegir la carpeta'
    return
  }
  if (res.cancelado || !res.rutaCompartida) return
  rutaEdicion.value = res.rutaCompartida
  mensaje.value =
    `Carpeta de este PC: ${res.carpetaLocal}. ${res.message ?? ''} ` +
    'Después pulse Probar.'
}

</script>

<template>
  <section class="carpeta-config">
    <RouterLink to="/configuracion" class="volver">← Configuración</RouterLink>
    <h2>Carpeta compartida</h2>
    <p class="intro">
      Carpeta de documentos e imágenes para todos los PCs de esta instalación. Windows la publica en
      el PC anfitrión y los demás PCs la abren por la red local.
    </p>

    <p v-if="cargando">Cargando…</p>
    <template v-else>
      <p v-if="!carpeta" class="aviso">
        Esta pantalla solo funciona en Descartes Electron, en el PC del puesto.
      </p>

      <div class="panel">
        <h3>Ruta de red</h3>
        <div class="fila">
          <input
            v-model="rutaEdicion"
            maxlength="500"
            placeholder="\\PC-CAJA\Descartes"
            :disabled="!carpeta"
          />
          <button type="button" :disabled="!carpeta || probando" @click="elegirLocal">
            Elegir carpeta de este PC…
          </button>
          <button type="button" :disabled="!carpeta || probando || !rutaEdicion.trim()" @click="probar">
            {{ probando ? 'Comprobando…' : 'Probar' }}
          </button>
          <button
            class="principal"
            type="button"
            :disabled="!carpeta || guardando || (rutaEdicion.trim() !== '' && !probada)"
            @click="guardar"
          >
            {{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
        <p class="nota">
          En el PC anfitrión, la carpeta debe estar compartida en Windows con el mismo nombre y permiso
          de escritura. En los demás PCs escriba la ruta a mano. No use letras de unidad (Z:): solo
          existen en un PC. Para quitar la carpeta, borre la ruta y guarde.
        </p>
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="ok">{{ mensaje }}</p>
      </div>

      <div v-if="carpeta && rutaGuardada" class="panel">
        <h3>Explorador</h3>
        <CarpetaCompartidaExplorador :ruta="rutaGuardada" />
      </div>
    </template>
  </section>
</template>

<style scoped>
.carpeta-config { max-width: 58rem; }
.volver { color: #2563eb; text-decoration: none; font-size: .85rem; }
h2 { margin: .6rem 0 .25rem; }
h3 { margin: 0 0 .7rem; font-size: .95rem; color: #0f172a; }
.intro { margin: 0 0 1rem; color: #64748b; }
.panel { margin-bottom: 1rem; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; }
.fila { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.fila input { flex: 1 1 22rem; }
input { padding: .5rem .6rem; border: 1px solid #cbd5e1; border-radius: 7px; }
button { padding: .45rem .8rem; border: 1px solid #94a3b8; border-radius: 7px; background: #fff; cursor: pointer; }
button.principal { color: #fff; border-color: #1d4ed8; background: #2563eb; }
button:disabled { opacity: .5; cursor: not-allowed; }
.nota { margin: .8rem 0 0; padding: .6rem; background: #f8fafc; color: #64748b; font-size: .8rem; border-radius: 7px; }
.aviso { padding: .7rem; background: #fffbeb; color: #92400e; border-radius: 7px; }
.error { color: #b91c1c; }
.ok { color: #047857; }
</style>
