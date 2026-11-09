<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'

const props = defineProps<{
  codigoTienda: string
  deshabilitado?: boolean
}>()

const codigo = computed(() => String(props.codigoTienda ?? '').trim())
const archivo = ref('')
const previewUrl = ref('')
const guardando = ref(false)
const aviso = ref('')
const avisoTipo = ref<'ok' | 'error' | 'aviso'>('aviso')
const inputArchivo = ref<HTMLInputElement | null>(null)

function pintar(texto: string, tipo: 'ok' | 'error' | 'aviso' = 'aviso') {
  aviso.value = texto
  avisoTipo.value = tipo
}

async function refrescar() {
  previewUrl.value = ''
  archivo.value = ''
  if (!codigo.value || props.deshabilitado) return
  try {
    const { data } = await api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(codigo.value)}/logo-encargo`)
    archivo.value = String(data.archivo ?? '')
    if (data.configurado) {
      previewUrl.value = `/api/encargos/tiendas/${encodeURIComponent(codigo.value)}/logo?t=${Date.now()}`
    }
  } catch {
    archivo.value = ''
  }
}

watch(() => [codigo.value, props.deshabilitado] as const, () => {
  void refrescar()
}, { immediate: true })

async function onArchivo(evento: Event) {
  const input = evento.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !codigo.value || props.deshabilitado) return
  guardando.value = true
  aviso.value = ''
  try {
    const cuerpo = new FormData()
    cuerpo.append('archivo', file)
    const { data } = await api.post(
      `/api/mantenimiento/tiendas/${encodeURIComponent(codigo.value)}/logo-encargo`,
      cuerpo,
      { headers: { 'Content-Type': undefined } }
    )
    archivo.value = String(data.archivo ?? file.name)
    previewUrl.value = `/api/encargos/tiendas/${encodeURIComponent(codigo.value)}/logo?t=${Date.now()}`
    pintar(`Logo de encargos guardado: ${archivo.value}`, 'ok')
  } catch (e) {
    pintar(extractApiError(e, 'No se pudo guardar el logo'), 'error')
  } finally {
    guardando.value = false
  }
}

async function quitar() {
  if (!codigo.value || guardando.value) return
  guardando.value = true
  aviso.value = ''
  try {
    await api.delete(`/api/mantenimiento/tiendas/${encodeURIComponent(codigo.value)}/logo-encargo`)
    archivo.value = ''
    previewUrl.value = ''
    pintar('La tienda ya no tiene logo en las etiquetas de encargos', 'ok')
  } catch (e) {
    pintar(extractApiError(e, 'No se pudo quitar el logo'), 'error')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <section class="tienda-logo-panel">
    <h3>Logo de etiquetas de encargos</h3>
    <p class="hint">
      Se imprime al principio de la etiqueta del encargo cuando el material se entrega en esta tienda.
      <template v-if="archivo"> Archivo: <strong>{{ archivo }}</strong>.</template>
    </p>
    <div class="logo-row">
      <div v-if="previewUrl" class="logo-preview">
        <img :src="previewUrl" alt="Logo de encargos" />
      </div>
      <div v-else class="logo-preview vacio">Sin logo</div>
      <div class="logo-acciones">
        <div class="logo-botones">
          <button type="button" class="btn-primary" :disabled="guardando || deshabilitado || !codigo" @click="inputArchivo?.click()">
            {{ guardando ? 'Guardando…' : 'Elegir archivo…' }}
          </button>
          <button type="button" :disabled="guardando || !archivo" @click="quitar">Quitar</button>
        </div>
        <input ref="inputArchivo" type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden @change="onArchivo" />
        <p v-if="!codigo || deshabilitado" class="hint mini">Guarde la tienda con un código antes de asignar el logo.</p>
        <p v-if="aviso" class="aviso-local" :class="`aviso-local-${avisoTipo}`">{{ aviso }}</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.tienda-logo-panel { margin: 0.75rem 0 1rem; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
.tienda-logo-panel h3 { margin: 0 0 0.35rem; font-size: 0.95rem; }
.hint { margin: 0 0 0.5rem; font-size: 0.78rem; color: #64748b; }
.hint.mini { margin: 0.35rem 0 0; }
.logo-row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start; }
.logo-preview { width: 180px; height: 64px; border: 1px dashed #cbd5e1; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: #fff; overflow: hidden; }
.logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.logo-preview.vacio { font-size: 0.75rem; color: #94a3b8; }
.logo-acciones { display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-start; flex: 1; }
.logo-botones { display: flex; gap: 0.5rem; }
.btn-primary { background: #2563eb; color: #fff; border: 1px solid #1d4ed8; border-radius: 6px; padding: 0.35rem 0.85rem; cursor: pointer; }
button:not(.btn-primary) { border: 1px solid #94a3b8; border-radius: 6px; padding: 0.35rem 0.85rem; background: #fff; cursor: pointer; }
button:disabled { opacity: 0.55; cursor: not-allowed; }
.aviso-local { margin: 0.35rem 0 0; padding: 0.4rem 0.55rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; }
.aviso-local-ok { color: #065f46; background: #d1fae5; }
.aviso-local-error { color: #7f1d1d; background: #fee2e2; }
.aviso-local-aviso { color: #92400e; background: #fef3c7; }
</style>
