<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'

const props = defineProps<{
  codigoTienda: string
  deshabilitado?: boolean
}>()

const codigo = computed(() => String(props.codigoTienda ?? '').trim())
const articulo = ref('')
const descripcion = ref('')
const existe = ref<boolean | null>(null)
const guardando = ref(false)
const aviso = ref('')
const avisoTipo = ref<'ok' | 'error' | 'aviso'>('aviso')

function pintar(texto: string, tipo: 'ok' | 'error' | 'aviso' = 'aviso') {
  aviso.value = texto
  avisoTipo.value = tipo
}

async function comprobarArticulo(codigoArticulo: string) {
  descripcion.value = ''
  existe.value = null
  const buscado = codigoArticulo.trim()
  if (!buscado) return
  try {
    const { data } = await api.get(`/api/mantenimiento/articulos/${encodeURIComponent(buscado)}`)
    descripcion.value = String(data.descripcion ?? data.Descripcion ?? '').trim()
    existe.value = true
  } catch {
    existe.value = false
  }
}

async function refrescar() {
  articulo.value = ''
  descripcion.value = ''
  existe.value = null
  aviso.value = ''
  if (!codigo.value || props.deshabilitado) return
  try {
    const { data } = await api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(codigo.value)}/articulo-encargo`)
    articulo.value = String(data.articulo ?? '')
    await comprobarArticulo(articulo.value)
  } catch {
    articulo.value = ''
  }
}

watch(() => [codigo.value, props.deshabilitado] as const, () => {
  void refrescar()
}, { immediate: true })

async function guardar() {
  if (!codigo.value || props.deshabilitado || guardando.value) return
  guardando.value = true
  aviso.value = ''
  try {
    const { data } = await api.put(
      `/api/mantenimiento/tiendas/${encodeURIComponent(codigo.value)}/articulo-encargo`,
      { articulo: articulo.value.trim() }
    )
    articulo.value = String(data.articulo ?? '')
    await comprobarArticulo(articulo.value)
    if (!articulo.value) {
      pintar('Esta tienda no tiene artículo por defecto. El albarán se crea sin línea.', 'ok')
    } else if (existe.value === false) {
      pintar('Guardado. Ese artículo no existe: el albarán se creará sin línea hasta que exista.', 'aviso')
    } else {
      pintar('Artículo por defecto guardado. El albarán llevará una línea con los rodillos del encargo.', 'ok')
    }
  } catch (e) {
    pintar(extractApiError(e, 'No se pudo guardar el artículo'), 'error')
  } finally {
    guardando.value = false
  }
}

async function quitar() {
  articulo.value = ''
  await guardar()
}
</script>

<template>
  <section class="tienda-articulo-panel">
    <h3>Artículo por defecto del encargo</h3>
    <p class="hint">
      Opcional. Si el código existe, al crear el encargo el albarán incluye una línea con tantas unidades como rodillos.
      Si está vacío o el artículo no existe, el albarán no lleva ninguna línea.
    </p>
    <div class="fila">
      <label>Código
        <input v-model="articulo" maxlength="18" :disabled="deshabilitado || !codigo || guardando" />
      </label>
      <button type="button" class="btn-primary" :disabled="guardando || deshabilitado || !codigo" @click="guardar">
        {{ guardando ? 'Guardando…' : 'Guardar' }}
      </button>
      <button type="button" :disabled="guardando || !articulo" @click="quitar">Quitar</button>
    </div>
    <p v-if="articulo && existe && descripcion" class="hint">{{ descripcion }}</p>
    <p v-else-if="articulo && existe === false" class="hint falta">Ese artículo no está en el catálogo.</p>
    <p v-if="!codigo || deshabilitado" class="hint mini">Guarde la tienda con un código antes de asignar el artículo.</p>
    <p v-if="aviso" class="aviso-local" :class="`aviso-local-${avisoTipo}`">{{ aviso }}</p>
  </section>
</template>

<style scoped>
.tienda-articulo-panel { margin: 0.75rem 0 1rem; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
.tienda-articulo-panel h3 { margin: 0 0 0.35rem; font-size: 0.95rem; }
.hint { margin: 0 0 0.5rem; font-size: 0.78rem; color: #64748b; }
.hint.mini { margin: 0.35rem 0 0; }
.hint.falta { color: #92400e; }
.fila { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: flex-end; }
label { display: flex; flex-direction: column; gap: 0.15rem; font-size: 0.8rem; }
input { height: 1.8rem; width: 10rem; padding: 0 0.4rem; }
.btn-primary { background: #2563eb; color: #fff; border: 1px solid #1d4ed8; border-radius: 6px; padding: 0.35rem 0.85rem; cursor: pointer; }
button:not(.btn-primary) { border: 1px solid #94a3b8; border-radius: 6px; padding: 0.35rem 0.85rem; background: #fff; cursor: pointer; }
button:disabled { opacity: 0.55; cursor: not-allowed; }
.aviso-local { margin: 0.35rem 0 0; padding: 0.4rem 0.55rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; }
.aviso-local-ok { color: #065f46; background: #d1fae5; }
.aviso-local-error { color: #7f1d1d; background: #fee2e2; }
.aviso-local-aviso { color: #92400e; background: #fef3c7; }
</style>
