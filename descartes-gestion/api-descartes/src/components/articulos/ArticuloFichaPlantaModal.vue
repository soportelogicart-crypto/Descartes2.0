<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

type Grupo = { tipo: string; codigo: number; descripcion: string }

type FichaPlanta = {
  codigo?: number | null
  nombreFormatoFicha?: string
  nombreBotanico?: string
  nombreComun?: string
  sinonimos?: string
  descripcion?: string
  consejo?: string
  imagen?: string
  imagen2?: string
  exposicion?: number | null
  exposicion2?: number | null
  exposicion3?: number | null
  riego?: number | null
  riego2?: number | null
  porte?: number | null
  hoja?: number | null
  hoja2?: number | null
  aromaticas?: number | null
  crecimiento?: number | null
  floracion?: string
  poda?: string
  altura?: string
  anchura?: string
  profundidad?: string
  temperatura?: string
}

const props = defineProps<{
  open: boolean
  articuloCodigo: string
  articuloDescripcion?: string
  readonly?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  guardado: []
}>()

const loading = ref(false)
const saving = ref(false)
const modoEdicion = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ficha = ref<FichaPlanta>({})
const grupos = ref<Grupo[]>([])
const mostrarBuscar = ref(false)
const buscarQ = ref('')
const buscarItems = ref<{ codigo: number; nombreBotanico: string; nombreComun: string }[]>([])

const puedeEditarAhora = computed(() => !props.readonly && modoEdicion.value)

function vacia(): FichaPlanta {
  return {
    codigo: null,
    nombreFormatoFicha: '',
    nombreBotanico: '',
    nombreComun: '',
    sinonimos: '',
    descripcion: '',
    consejo: '',
    imagen: '',
    imagen2: '',
    exposicion: null,
    exposicion2: null,
    exposicion3: null,
    riego: null,
    riego2: null,
    porte: null,
    hoja: null,
    hoja2: null,
    aromaticas: null,
    crecimiento: null,
    floracion: '',
    poda: '',
    altura: '',
    anchura: '',
    profundidad: '',
    temperatura: '',
  }
}

function opciones(tipo: string) {
  return grupos.value.filter((g) => g.tipo === tipo)
}

function imagenSrc(path?: string) {
  const raw = String(path ?? '').trim()
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw) || raw.startsWith('/') || raw.startsWith('data:')) return raw
  return ''
}

watch(
  () => [props.open, props.articuloCodigo] as const,
  async ([open, codigo]) => {
    if (open && codigo) await cargar()
  }
)

async function cargar() {
  loading.value = true
  error.value = null
  mensaje.value = null
  modoEdicion.value = false
  try {
    const { data } = await api.get(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.articuloCodigo)}/ficha-botanica`
    )
    grupos.value = data.grupos ?? []
    ficha.value = data.ficha ? { ...vacia(), ...data.ficha } : vacia()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar la ficha botanica')
    ficha.value = vacia()
  } finally {
    loading.value = false
  }
}

function onNuevo() {
  if (props.readonly) return
  ficha.value = vacia()
  modoEdicion.value = true
  mensaje.value = 'Nueva ficha: complete los datos y pulse Guardar'
}

function onModificar() {
  if (props.readonly) return
  modoEdicion.value = true
}

async function onBorrar() {
  if (props.readonly || !ficha.value.codigo) return
  if (!confirm('Borrar la ficha botanica vinculada a este articulo?')) return
  try {
    await api.delete(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.articuloCodigo)}/ficha-botanica`
    )
    ficha.value = vacia()
    modoEdicion.value = false
    mensaje.value = 'Ficha eliminada'
    emit('guardado')
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo borrar la ficha')
  }
}

async function onGuardar() {
  if (!puedeEditarAhora.value) return
  saving.value = true
  error.value = null
  try {
    const { data } = await api.put(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.articuloCodigo)}/ficha-botanica`,
      { ficha: ficha.value }
    )
    ficha.value = data.ficha ? { ...vacia(), ...data.ficha } : ficha.value
    modoEdicion.value = false
    mensaje.value = 'Ficha guardada (GardenDocumental.Plantas)'
    emit('guardado')
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar la ficha')
  } finally {
    saving.value = false
  }
}

async function abrirBuscar() {
  mostrarBuscar.value = true
  buscarQ.value = ''
  await buscarFichas()
}

async function buscarFichas() {
  const { data } = await api.get('/api/mantenimiento/fichas-botanicas', {
    params: { q: buscarQ.value.trim() },
  })
  buscarItems.value = data.items ?? []
}

async function seleccionarBusqueda(codigo: number) {
  const { data } = await api.get(`/api/mantenimiento/fichas-botanicas/${codigo}`)
  ficha.value = { ...vacia(), ...data }
  mostrarBuscar.value = false
  modoEdicion.value = !props.readonly
  mensaje.value = `Ficha ${codigo} cargada. Guarde para vincularla al articulo.`
}

function imprimir() {
  window.print()
}
</script>

<template>
  <div v-if="open" class="overlay no-print-overlay" @click.self="emit('cerrar')">
    <div class="modal ficha-botanica-print">
      <header class="modal-header no-print">
        <div class="tools">
          <button type="button" class="tool" :disabled="readonly || loading" @click="onNuevo">Nuevo</button>
          <button type="button" class="tool" :disabled="readonly || loading || modoEdicion" @click="onModificar">
            Modificar
          </button>
          <button type="button" class="tool" :disabled="readonly || loading || !ficha.codigo" @click="onBorrar">
            Borrar
          </button>
          <button type="button" class="tool" :disabled="loading" @click="abrirBuscar">Buscar</button>
          <button
            v-if="modoEdicion"
            type="button"
            class="tool primary"
            :disabled="saving"
            @click="onGuardar"
          >
            Guardar
          </button>
          <button type="button" class="tool" @click="imprimir">Listado</button>
        </div>
        <button type="button" class="close" @click="emit('cerrar')">×</button>
      </header>

      <p class="sub no-print">
        Articulo {{ articuloCodigo }} — {{ articuloDescripcion }}
        <span v-if="ficha.codigo"> · Ficha #{{ ficha.codigo }}</span>
      </p>
      <p v-if="error" class="error no-print">{{ error }}</p>
      <p v-else-if="mensaje" class="ok no-print">{{ mensaje }}</p>
      <p v-else-if="loading" class="hint no-print">Cargando...</p>

      <div class="tabs">
        <span class="tab active">General</span>
      </div>

      <div class="form-grid" :class="{ readonly: !puedeEditarAhora }">
        <div class="col-left">
          <label class="field">
            <span>Ficha</span>
            <input v-model="ficha.nombreFormatoFicha" :readonly="!puedeEditarAhora" />
          </label>
          <label class="field">
            <span>Nombre Botanico</span>
            <input v-model="ficha.nombreBotanico" :readonly="!puedeEditarAhora" />
          </label>
          <label class="field">
            <span>Nombre Comun</span>
            <input v-model="ficha.nombreComun" :readonly="!puedeEditarAhora" />
          </label>
          <label class="field">
            <span>Sinonimo</span>
            <input v-model="ficha.sinonimos" :readonly="!puedeEditarAhora" />
          </label>
          <label class="field area">
            <span>Descripcion</span>
            <textarea v-model="ficha.descripcion" rows="8" :readonly="!puedeEditarAhora" />
          </label>
          <label class="field area">
            <span>Consejo</span>
            <textarea v-model="ficha.consejo" rows="3" :readonly="!puedeEditarAhora" />
          </label>
        </div>

        <div class="col-right">
          <div class="fotos">
            <div class="foto">
              <img v-if="imagenSrc(ficha.imagen)" :src="imagenSrc(ficha.imagen)" alt="" />
              <div v-else class="foto-ph">Foto 1</div>
              <input
                v-if="puedeEditarAhora"
                v-model="ficha.imagen"
                class="path"
                placeholder="Ruta Imagen"
              />
            </div>
            <div class="foto">
              <img v-if="imagenSrc(ficha.imagen2)" :src="imagenSrc(ficha.imagen2)" alt="" />
              <div v-else class="foto-ph">Foto 2</div>
              <input
                v-if="puedeEditarAhora"
                v-model="ficha.imagen2"
                class="path"
                placeholder="Ruta Imagen 2"
              />
            </div>
          </div>

          <div class="attrs">
            <select v-model.number="ficha.exposicion" :disabled="!puedeEditarAhora">
              <option :value="null">Exposicion</option>
              <option v-for="g in opciones('E')" :key="'e' + g.codigo" :value="g.codigo">
                {{ g.descripcion }}
              </option>
            </select>
            <select v-model.number="ficha.exposicion2" :disabled="!puedeEditarAhora">
              <option :value="null">Exposicion 2</option>
              <option v-for="g in opciones('E')" :key="'e2' + g.codigo" :value="g.codigo">
                {{ g.descripcion }}
              </option>
            </select>
            <select v-model.number="ficha.exposicion3" :disabled="!puedeEditarAhora">
              <option :value="null">Exposicion 3</option>
              <option v-for="g in opciones('E')" :key="'e3' + g.codigo" :value="g.codigo">
                {{ g.descripcion }}
              </option>
            </select>
            <label class="inline">
              <span>Crecimiento</span>
              <select v-model.number="ficha.crecimiento" :disabled="!puedeEditarAhora">
                <option :value="null">--</option>
                <option v-for="g in opciones('C')" :key="'c' + g.codigo" :value="g.codigo">
                  {{ g.descripcion }}
                </option>
              </select>
            </label>
            <select v-model.number="ficha.riego" :disabled="!puedeEditarAhora">
              <option :value="null">Riego</option>
              <option v-for="g in opciones('R')" :key="'r' + g.codigo" :value="g.codigo">
                {{ g.descripcion }}
              </option>
            </select>
            <select v-model.number="ficha.riego2" :disabled="!puedeEditarAhora">
              <option :value="null">Riego 2</option>
              <option v-for="g in opciones('R')" :key="'r2' + g.codigo" :value="g.codigo">
                {{ g.descripcion }}
              </option>
            </select>
          </div>

          <div class="row2">
            <label>
              <span>Floracion</span>
              <input v-model="ficha.floracion" :readonly="!puedeEditarAhora" />
            </label>
            <label>
              <span>Poda</span>
              <input v-model="ficha.poda" :readonly="!puedeEditarAhora" />
            </label>
          </div>
          <div class="row2">
            <label>
              <span>Altura</span>
              <input v-model="ficha.altura" :readonly="!puedeEditarAhora" />
            </label>
            <label>
              <span>Anchura</span>
              <input v-model="ficha.anchura" :readonly="!puedeEditarAhora" />
            </label>
          </div>
          <div class="row2">
            <label>
              <span>Profundidad</span>
              <input v-model="ficha.profundidad" :readonly="!puedeEditarAhora" />
            </label>
            <label>
              <span>Temperatura</span>
              <input v-model="ficha.temperatura" :readonly="!puedeEditarAhora" />
            </label>
          </div>
          <div class="row2">
            <label>
              <span>Forma / Porte</span>
              <select v-model.number="ficha.porte" :disabled="!puedeEditarAhora">
                <option :value="null">--</option>
                <option v-for="g in opciones('P')" :key="'p' + g.codigo" :value="g.codigo">
                  {{ g.descripcion }}
                </option>
              </select>
            </label>
            <label>
              <span>Aromaticas</span>
              <select v-model.number="ficha.aromaticas" :disabled="!puedeEditarAhora">
                <option :value="null">--</option>
                <option v-for="g in opciones('A')" :key="'a' + g.codigo" :value="g.codigo">
                  {{ g.descripcion }}
                </option>
              </select>
            </label>
          </div>
          <div class="row2">
            <select v-model.number="ficha.hoja" :disabled="!puedeEditarAhora">
              <option :value="null">Hoja</option>
              <option v-for="g in opciones('H')" :key="'h' + g.codigo" :value="g.codigo">
                {{ g.descripcion }}
              </option>
            </select>
            <select v-model.number="ficha.hoja2" :disabled="!puedeEditarAhora">
              <option :value="null">Hoja 2</option>
              <option v-for="g in opciones('H')" :key="'h2' + g.codigo" :value="g.codigo">
                {{ g.descripcion }}
              </option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div v-if="mostrarBuscar" class="buscar-overlay" @click.self="mostrarBuscar = false">
      <div class="buscar-modal">
        <header>
          <h4>Buscar ficha</h4>
          <button type="button" @click="mostrarBuscar = false">×</button>
        </header>
        <div class="buscar-bar">
          <input v-model="buscarQ" placeholder="Codigo / nombre..." @keydown.enter.prevent="buscarFichas" />
          <button type="button" @click="buscarFichas">Buscar</button>
        </div>
        <ul>
          <li v-for="item in buscarItems" :key="item.codigo" @click="seleccionarBusqueda(item.codigo)">
            <strong>#{{ item.codigo }}</strong>
            {{ item.nombreBotanico || item.nombreComun }}
          </li>
        </ul>
      </div>
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
  z-index: 70;
  padding: 0.75rem;
}

.modal {
  width: min(980px, 100%);
  max-height: 94vh;
  overflow: auto;
  background: #e8edf2;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  background: linear-gradient(180deg, #f8fafc, #e2e8f0);
  border-bottom: 1px solid #cbd5e1;
}

.tools {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.tool {
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  padding: 0.3rem 0.55rem;
  font-size: 0.75rem;
  cursor: pointer;
}

.tool.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.tool:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.close {
  border: none;
  background: transparent;
  font-size: 1.4rem;
  cursor: pointer;
}

.sub,
.hint,
.error,
.ok {
  margin: 0;
  padding: 0.35rem 0.75rem 0;
  font-size: 0.78rem;
}

.sub,
.hint {
  color: #64748b;
}

.error {
  color: #b91c1c;
}

.ok {
  color: #047857;
}

.tabs {
  padding: 0.35rem 0.75rem 0;
}

.tab {
  display: inline-block;
  padding: 0.25rem 0.6rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-bottom: none;
  border-radius: 4px 4px 0 0;
  font-size: 0.78rem;
  font-weight: 600;
}

.form-grid {
  display: grid;
  grid-template-columns: 1.1fr 1fr;
  gap: 0.65rem;
  padding: 0.5rem 0.75rem 0.9rem;
  background: #fff;
  border-top: 1px solid #94a3b8;
}

.col-left,
.col-right {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}

.field {
  display: grid;
  gap: 0.15rem;
  font-size: 0.75rem;
  color: #334155;
}

.field.area textarea {
  min-height: 5rem;
}

.fotos {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
}

.foto {
  border: 1px dashed #94a3b8;
  border-radius: 4px;
  background: #f8fafc;
  min-height: 120px;
  display: flex;
  flex-direction: column;
}

.foto img {
  width: 100%;
  height: 120px;
  object-fit: cover;
}

.foto-ph {
  flex: 1;
  display: grid;
  place-items: center;
  color: #94a3b8;
  font-size: 0.8rem;
  min-height: 120px;
}

.foto .path {
  border: none;
  border-top: 1px solid #e2e8f0;
  font-size: 0.7rem;
  padding: 0.2rem 0.3rem;
}

.attrs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.3rem;
}

.attrs .inline {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.35rem;
  align-items: center;
  font-size: 0.75rem;
}

.row2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
}

.row2 label {
  display: grid;
  gap: 0.1rem;
  font-size: 0.72rem;
  color: #475569;
}

input,
select,
textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  padding: 0.25rem 0.35rem;
  font-size: 0.8rem;
  background: #fff;
}

input:read-only,
textarea:read-only,
select:disabled {
  background: #eef2f6;
}

.buscar-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.35);
  display: grid;
  place-items: center;
  z-index: 80;
}

.buscar-modal {
  width: min(420px, 92vw);
  background: #fff;
  border-radius: 8px;
  padding: 0.75rem;
  max-height: 70vh;
  overflow: auto;
}

.buscar-modal header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.buscar-modal h4 {
  margin: 0;
}

.buscar-bar {
  display: flex;
  gap: 0.35rem;
  margin: 0.5rem 0;
}

.buscar-modal ul {
  list-style: none;
  margin: 0;
  padding: 0;
}

.buscar-modal li {
  padding: 0.4rem 0.3rem;
  border-bottom: 1px solid #e2e8f0;
  cursor: pointer;
  font-size: 0.85rem;
}

.buscar-modal li:hover {
  background: #eff6ff;
}

@media (max-width: 800px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}

@media print {
  .no-print {
    display: none !important;
  }
  .overlay {
    position: static;
    background: transparent;
    padding: 0;
  }
  .modal {
    box-shadow: none;
    max-height: none;
  }
}
</style>
