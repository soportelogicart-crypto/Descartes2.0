<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { getDescartesBridge, isElectronShell } from '@/bridge/electron'
import { cargarEmblemaEmpresa, invalidarEmblemaEmpresa } from '@/composables/cargarEmblemaEmpresa'

const props = defineProps<{
  codigoTienda: string
  /** Tienda aún no grabada: no se puede asignar logo. */
  deshabilitado?: boolean
}>()

const emit = defineEmits<{
  mensaje: [texto: string, tipo?: 'ok' | 'error' | 'aviso']
}>()

const enElectron = isElectronShell()
const previewUrl = ref('')
const carpetaLogos = ref('')
const cargando = ref(false)
const guardando = ref(false)
const avisoLocal = ref('')
const avisoLocalTipo = ref<'ok' | 'error' | 'aviso'>('aviso')

function pintarAviso(texto: string, tipo: 'ok' | 'error' | 'aviso' = 'aviso') {
  avisoLocal.value = texto
  avisoLocalTipo.value = tipo
  emit('mensaje', texto, tipo)
}

const codigo = computed(() => String(props.codigoTienda ?? '').trim())
const puedeUsar = computed(() => enElectron && codigo.value !== '' && !props.deshabilitado)

async function refrescar() {
  if (!puedeUsar.value) {
    previewUrl.value = ''
    return
  }
  cargando.value = true
  try {
    const bridge = getDescartesBridge()
    if (bridge?.getLogosDir) {
      const dir = await bridge.getLogosDir()
      if (dir.ok && dir.carpeta) carpetaLogos.value = dir.carpeta
    }
    previewUrl.value = await cargarEmblemaEmpresa(codigo.value)
  } finally {
    cargando.value = false
  }
}

watch(
  () => [codigo.value, props.deshabilitado] as const,
  () => {
    void refrescar()
  },
  { immediate: true }
)

function onElegirClick() {
  avisoLocal.value = ''
  if (!enElectron) {
    pintarAviso('Los logos locales solo funcionan en la aplicación Descartes 2.0 (Electron).', 'aviso')
    return
  }
  if (props.deshabilitado || !codigo.value) {
    pintarAviso('Guarde la tienda con un código antes de asignar el logo.', 'aviso')
    return
  }
  if (guardando.value) return
  void elegirYGuardar()
}

async function elegirYGuardar() {
  const bridge = getDescartesBridge()
  if (!bridge?.guardarLogoEmpresa) {
    pintarAviso(
      'Esta instalación de Descartes 2.0 no incluye la función de logos. Cierre la app, instale el instalador más reciente y vuelva a abrirla.',
      'aviso'
    )
    return
  }
  guardando.value = true
  try {
    invalidarEmblemaEmpresa(codigo.value)
    const res = await bridge.guardarLogoEmpresa(codigo.value)
    if (res.cancelado) return
    if (!res.ok) {
      pintarAviso(res.message ?? 'No se pudo guardar el logo', 'error')
      return
    }
    if (res.carpeta) carpetaLogos.value = res.carpeta
    previewUrl.value = res.dataUrl ?? (await cargarEmblemaEmpresa(codigo.value))
    pintarAviso(`Logo guardado para la tienda ${codigo.value}`, 'ok')
  } catch (e) {
    pintarAviso(e instanceof Error ? e.message : 'Error al guardar el logo', 'error')
  } finally {
    guardando.value = false
  }
}

async function abrirCarpeta() {
  avisoLocal.value = ''
  const bridge = getDescartesBridge()
  if (!bridge?.abrirCarpetaLogos) {
    pintarAviso(
      'No se puede abrir la carpeta: actualice Descartes 2.0 con el instalador más reciente.',
      'aviso'
    )
    return
  }
  try {
    const res = await bridge.abrirCarpetaLogos()
    if (res.carpeta) carpetaLogos.value = res.carpeta
    if (!res.ok) {
      pintarAviso(res.message ?? 'No se pudo abrir la carpeta de logos', 'error')
    }
  } catch (e) {
    pintarAviso(e instanceof Error ? e.message : 'Error al abrir la carpeta de logos', 'error')
  }
}
</script>

<template>
  <section v-if="enElectron" class="tienda-logo-panel">
    <h3>Logo en albarán / factura (este PC)</h3>
    <p class="hint">
      Se guarda en este ordenador con el nombre <strong>{{ codigo || '…' }}.png</strong> (u otro
      formato). Al imprimir desde Electron se usa el código de tienda.
    </p>
    <div class="logo-row">
      <div v-if="previewUrl" class="logo-preview">
        <img :src="previewUrl" alt="Vista previa logo" />
      </div>
      <div v-else class="logo-preview vacio">
        {{ cargando ? '…' : 'Sin logo' }}
      </div>
      <div class="logo-acciones">
        <div class="logo-botones">
          <button
            type="button"
            class="btn-primary"
            :class="{ 'is-disabled': guardando }"
            :disabled="guardando"
            @click="onElegirClick"
          >
            {{ guardando ? 'Guardando…' : 'Elegir imagen…' }}
          </button>
          <button type="button" @click="abrirCarpeta">Abrir carpeta logos</button>
        </div>
        <p
          v-if="avisoLocal"
          class="aviso-local"
          :class="`aviso-local-${avisoLocalTipo}`"
          role="alert"
        >
          {{ avisoLocal }}
        </p>
        <p v-if="!codigo || deshabilitado" class="hint mini">
          Guarde la tienda con un código antes de asignar el logo.
        </p>
        <p v-if="carpetaLogos" class="hint mini ruta" :title="carpetaLogos">{{ carpetaLogos }}</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.tienda-logo-panel {
  margin: 0.75rem 0 1rem;
  padding: 0.75rem 1rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
}
.tienda-logo-panel h3 {
  margin: 0 0 0.35rem;
  font-size: 0.95rem;
}
.hint {
  margin: 0 0 0.5rem;
  font-size: 0.78rem;
  color: #64748b;
}
.hint.mini {
  margin: 0.35rem 0 0;
}
.hint.ruta {
  word-break: break-all;
  font-size: 0.72rem;
}
.logo-row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: flex-start;
}
.logo-preview {
  width: 120px;
  height: 72px;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
  overflow: hidden;
}
.logo-preview img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
.logo-preview.vacio {
  font-size: 0.75rem;
  color: #94a3b8;
}
.logo-acciones {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  align-items: flex-start;
  flex: 1;
  min-width: 0;
}
.logo-botones {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
.btn-primary {
  background: #2563eb;
  color: #fff;
  border: 1px solid #1d4ed8;
  border-radius: 6px;
  padding: 0.35rem 0.85rem;
  cursor: pointer;
}
.btn-primary:disabled,
.btn-primary.is-disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.aviso-local {
  margin: 0.35rem 0 0;
  padding: 0.4rem 0.55rem;
  border-radius: 6px;
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.35;
  max-width: 100%;
  width: 100%;
}
.aviso-local-ok {
  color: #065f46;
  background: #d1fae5;
  border: 1px solid #34d399;
}
.aviso-local-error {
  color: #7f1d1d;
  background: #fee2e2;
  border: 1px solid #f87171;
}
.aviso-local-aviso {
  color: #92400e;
  background: #fef3c7;
  border: 1px solid #fbbf24;
}
button:not(.btn-primary) {
  border: 1px solid #94a3b8;
  border-radius: 6px;
  padding: 0.35rem 0.85rem;
  background: #fff;
  cursor: pointer;
}
</style>
