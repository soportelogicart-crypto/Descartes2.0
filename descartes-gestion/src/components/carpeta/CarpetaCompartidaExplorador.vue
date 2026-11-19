<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  getDescartesBridge,
  type CarpetaCompartidaElemento,
  type CarpetaCompartidaResultado,
} from '@/bridge/electron'

const props = withDefaults(
  defineProps<{
    ruta: string
    /** Acceso de la cabecera: solo archivos, abrir e insertar. Sin carpetas. */
    soloArchivos?: boolean
  }>(),
  { soloArchivos: false },
)

const bridge = getDescartesBridge()
const carpeta = bridge?.carpetaCompartida

const cargandoLista = ref(false)
const mensaje = ref('')
const error = ref('')
/** Posición dentro de la carpeta compartida, con `\` (vacía = raíz). */
const relativa = ref('')
const elementos = ref<CarpetaCompartidaElemento[]>([])
const nombreNueva = ref('')

const partes = computed(() => (relativa.value ? relativa.value.split('\\') : []))
const visibles = computed(() =>
  props.soloArchivos ? elementos.value.filter((el) => !el.carpeta) : elementos.value,
)

watch(
  () => props.ruta,
  () => {
    relativa.value = ''
    void cargarLista()
  },
  { immediate: true },
)

function formatearTamano(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toLocaleString('es-ES', { maximumFractionDigits: 1 })} KB`
  return `${(bytes / (1024 * 1024)).toLocaleString('es-ES', { maximumFractionDigits: 1 })} MB`
}

function formatearFecha(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('es-ES') : ''
}

async function cargarLista() {
  elementos.value = []
  if (!carpeta || !props.ruta) return
  cargandoLista.value = true
  try {
    const res = await carpeta.listar(props.ruta, relativa.value)
    if (!res.ok) {
      error.value = res.message ?? 'No se pudo leer la carpeta'
      return
    }
    error.value = ''
    elementos.value = res.elementos ?? []
  } finally {
    cargandoLista.value = false
  }
}

function entrar(nombre: string) {
  relativa.value = [...partes.value, nombre].join('\\')
  void cargarLista()
}

function irA(indice: number) {
  relativa.value = indice < 0 ? '' : partes.value.slice(0, indice + 1).join('\\')
  void cargarLista()
}

async function ejecutar(fn: () => Promise<CarpetaCompartidaResultado>, okNuevo?: () => void) {
  error.value = ''
  mensaje.value = ''
  try {
    const res = await fn()
    if (res.ok) {
      mensaje.value = res.message ?? ''
      okNuevo?.()
    } else {
      error.value = res.message ?? 'La operación no se pudo completar'
    }
  } finally {
    await cargarLista()
  }
}

function crearCarpeta() {
  const nombre = nombreNueva.value.trim()
  if (!carpeta || !nombre) return
  void ejecutar(
    () => carpeta.crear(props.ruta, relativa.value, nombre),
    () => {
      nombreNueva.value = ''
    },
  )
}

async function subirArchivos() {
  if (!carpeta) return
  error.value = ''
  mensaje.value = ''
  try {
    const res = await carpeta.subir(props.ruta, relativa.value)
    if (!res.ok) error.value = res.message ?? 'No se pudieron copiar los archivos'
    else if (!res.cancelado) mensaje.value = res.message ?? ''
  } finally {
    await cargarLista()
  }
}

function abrir(el: CarpetaCompartidaElemento) {
  if (!carpeta) return
  void ejecutar(() => carpeta.abrir(props.ruta, relativa.value, el.nombre))
}

function borrar(el: CarpetaCompartidaElemento) {
  if (!carpeta) return
  const aviso = el.carpeta
    ? `¿Eliminar la carpeta «${el.nombre}»? Solo se borra si está vacía.`
    : `¿Eliminar «${el.nombre}»? No se puede deshacer.`
  const confirmado = window.confirm(aviso)
  bridge?.reenfocar?.()
  if (!confirmado) return
  void ejecutar(() => carpeta.borrar(props.ruta, relativa.value, el.nombre))
}
</script>

<template>
  <div class="explorador">
    <p v-if="!carpeta" class="aviso">Esta ventana solo funciona en Descartes Electron, en el PC del puesto.</p>
    <template v-else>
      <nav v-if="!soloArchivos" class="migas">
        <button type="button" class="enlace" @click="irA(-1)">Carpeta compartida</button>
        <template v-for="(p, i) in partes" :key="i">
          <span>/</span>
          <button type="button" class="enlace" @click="irA(i)">{{ p }}</button>
        </template>
      </nav>

      <div class="barra">
        <button type="button" :disabled="cargandoLista" @click="subirArchivos">
          {{ soloArchivos ? 'Insertar archivos…' : 'Subir archivos…' }}
        </button>
        <template v-if="!soloArchivos">
          <input
            v-model="nombreNueva"
            maxlength="180"
            placeholder="Nombre de la nueva carpeta"
            @keyup.enter="crearCarpeta"
          />
          <button type="button" :disabled="!nombreNueva.trim() || cargandoLista" @click="crearCarpeta">
            Crear carpeta
          </button>
        </template>
        <button type="button" :disabled="cargandoLista" @click="cargarLista">Actualizar</button>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="mensaje" class="ok">{{ mensaje }}</p>

      <table>
        <thead>
          <tr>
            <th>Nombre</th>
            <th class="num">Tamaño</th>
            <th>Modificado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!soloArchivos && partes.length">
            <td colspan="4">
              <button type="button" class="enlace" @click="irA(partes.length - 2)">..</button>
            </td>
          </tr>
          <tr v-for="el in visibles" :key="el.nombre">
            <td>
              <button v-if="el.carpeta" type="button" class="enlace" @click="entrar(el.nombre)">
                {{ el.nombre }}
              </button>
              <span v-else>{{ el.nombre }}</span>
            </td>
            <td class="num">{{ el.carpeta ? '' : formatearTamano(el.tamano) }}</td>
            <td>{{ formatearFecha(el.modificado) }}</td>
            <td class="acciones-fila">
              <button type="button" @click="abrir(el)">Abrir</button>
              <button v-if="!soloArchivos" type="button" class="peligro" @click="borrar(el)">Eliminar</button>
            </td>
          </tr>
          <tr v-if="!cargandoLista && visibles.length === 0">
            <td colspan="4" class="vacio">{{ soloArchivos ? 'No hay archivos' : 'Carpeta vacía' }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
.explorador { min-width: 0; }
.aviso { margin: 0; padding: .7rem; background: #fffbeb; color: #92400e; border-radius: 7px; }
.barra { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .8rem; }
.barra input { flex: 1 1 14rem; padding: .5rem .6rem; border: 1px solid #cbd5e1; border-radius: 7px; }
button { padding: .45rem .8rem; border: 1px solid #94a3b8; border-radius: 7px; background: #fff; cursor: pointer; }
button.peligro { color: #b91c1c; border-color: #fca5a5; }
button:disabled { opacity: .5; cursor: not-allowed; }
button.enlace { border: none; padding: 0; background: none; color: #1d4ed8; }
.error { color: #b91c1c; }
.ok { color: #047857; }
.migas { display: flex; flex-wrap: wrap; gap: .3rem; align-items: center; margin-bottom: .7rem; font-size: .9rem; }
table { width: 100%; border-collapse: collapse; font-size: .9rem; }
th, td { padding: .45rem .5rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
th.num, td.num { text-align: right; white-space: nowrap; }
.acciones-fila { white-space: nowrap; text-align: right; }
.acciones-fila button { margin-left: .3rem; }
.vacio { color: #64748b; text-align: center; }
</style>
