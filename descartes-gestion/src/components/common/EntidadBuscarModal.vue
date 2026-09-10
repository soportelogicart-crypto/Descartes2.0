<script setup lang="ts">
import { nextTick, onUnmounted, ref, watch } from 'vue'
import { api } from '@/api/client'

export type EntidadBuscarResultado = {
  codigo: string
  etiqueta: string
}

const props = defineProps<{
  open: boolean
  entidad:
    | 'articulos'
    | 'proveedores'
    | 'clientes'
    | 'trabajadores'
    | 'puestos-trabajo'
    | 'tiendas'
    | 'familias'
    | 'macrofamilias'
    | 'subfamilias'
    | 'secciones'
    | 'subsecciones'
    | 'formas-pago'
    | 'bancos'
    | 'cuentas'
    | 'cuentas-banco'
  titulo?: string
  busquedaInicial?: string
  /** Deja la fila de este codigo señalada, sin filtrar el listado. */
  codigoActual?: string
}>()

const emit = defineEmits<{
  seleccionar: [resultado: EntidadBuscarResultado]
  cerrar: []
}>()

type Fila = { codigo: string; etiqueta: string }

const q = ref('')
const gridWrap = ref<HTMLElement | null>(null)
const loading = ref(false)
const items = ref<Fila[]>([])
const indice = ref(0)
const error = ref<string | null>(null)

const tituloModal = () => {
  if (props.titulo) return props.titulo
  if (props.entidad === 'articulos') return 'Buscar articulo'
  if (props.entidad === 'clientes') return 'Buscar cliente'
  if (props.entidad === 'trabajadores') return 'Buscar vendedor'
  if (props.entidad === 'puestos-trabajo') return 'Buscar puesto'
  if (props.entidad === 'tiendas') return 'Buscar empresa'
  if (props.entidad === 'familias') return 'Buscar familia'
  if (props.entidad === 'macrofamilias') return 'Buscar macrofamilia'
  if (props.entidad === 'subfamilias') return 'Buscar subfamilia'
  if (props.entidad === 'secciones') return 'Buscar seccion'
  if (props.entidad === 'subsecciones') return 'Buscar subseccion'
  if (props.entidad === 'formas-pago') return 'Buscar forma de pago'
  if (props.entidad === 'bancos') return 'Buscar banco'
  if (props.entidad === 'cuentas') return 'Buscar cuenta'
  if (props.entidad === 'cuentas-banco') return 'Buscar banco'
  return 'Buscar proveedor'
}

/** Espera entre pulsaciones antes de consultar, para no lanzar una peticion por tecla. */
const RETARDO_TECLEO = 250
let temporizador: ReturnType<typeof setTimeout> | null = null
/** La busqueda inicial ya la lanza el watch de open; el de q no debe repetirla. */
let omitirBusquedaDeQ = false

function cancelarBusquedaPendiente() {
  if (temporizador === null) return
  clearTimeout(temporizador)
  temporizador = null
}

watch(
  () => props.open,
  async (abierto) => {
    cancelarBusquedaPendiente()
    if (!abierto) return
    const inicial = props.busquedaInicial?.trim() ?? ''
    omitirBusquedaDeQ = inicial !== q.value
    q.value = inicial
    indice.value = 0
    error.value = null
    await buscar()
    await posicionarEnActual()
  }
)

// Busca mientras se escribe: no hay boton de lupa.
watch(q, () => {
  if (omitirBusquedaDeQ) {
    omitirBusquedaDeQ = false
    return
  }
  if (!props.open) return
  cancelarBusquedaPendiente()
  temporizador = setTimeout(() => {
    temporizador = null
    indice.value = 0
    void buscar()
  }, RETARDO_TECLEO)
})

onUnmounted(cancelarBusquedaPendiente)

async function posicionarEnActual() {
  const codigo = props.codigoActual?.trim()
  if (!codigo) return
  const i = items.value.findIndex((fila) => fila.codigo.trim() === codigo)
  if (i < 0) return
  indice.value = i
  await nextTick()
  gridWrap.value?.querySelector('tr.selected')?.scrollIntoView({ block: 'center' })
}

/** Descarta respuestas de consultas que ya han quedado atras al seguir tecleando. */
let ultimaPeticion = 0

async function buscar() {
  const peticion = ++ultimaPeticion
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get(`/api/mantenimiento/${props.entidad}`, {
      params: { q: q.value, page: 1, pageSize: 200 },
    })
    if (peticion !== ultimaPeticion) return
    items.value = (data.items ?? []).map((item: Record<string, unknown>) => {
      const codigo = String(item.codigo ?? item.puesto ?? '').trim()
      let etiqueta = ''
      if (props.entidad === 'articulos') {
        etiqueta = String(item.descripcion ?? item.nombre ?? '')
      } else if (props.entidad === 'clientes') {
        etiqueta = String(item.nombre ?? item.razonSocial ?? '')
      } else if (props.entidad === 'trabajadores') {
        etiqueta = String(item.nombre ?? item.descripcion ?? '')
      } else if (props.entidad === 'puestos-trabajo') {
        etiqueta = String(item.descripcion ?? item.nombre ?? '')
      } else if (props.entidad === 'tiendas') {
        etiqueta = String(item.nombre ?? item.nombreFiscal ?? '')
      } else {
        etiqueta = String(item.nombre ?? item.razonSocial ?? item.descripcion ?? '')
      }
      return { codigo, etiqueta }
    })
    indice.value = Math.min(indice.value, Math.max(0, items.value.length - 1))
  } catch {
    if (peticion !== ultimaPeticion) return
    error.value = 'No se pudo cargar el listado'
    items.value = []
  } finally {
    if (peticion === ultimaPeticion) loading.value = false
  }
}

function seleccionarIndice(i: number) {
  indice.value = i
}

function aceptar(i?: number) {
  const fila = items.value[i ?? indice.value]
  if (!fila) return
  emit('seleccionar', { codigo: fila.codigo, etiqueta: fila.etiqueta })
  emit('cerrar')
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cerrar')">
      <div class="modal">
        <header class="modal-header">
          <h3>{{ tituloModal() }}</h3>
          <button type="button" class="btn-cerrar" @click="emit('cerrar')">Cerrar</button>
        </header>

        <div class="buscar-bar">
          <input
            v-model="q"
            type="search"
            :placeholder="
              entidad === 'clientes'
                ? 'Codigo o razon social...'
                : entidad === 'proveedores'
                  ? 'Codigo o razon social...'
                  : entidad === 'trabajadores' || entidad === 'tiendas'
                    ? 'Codigo o nombre...'
                    : 'Codigo o descripcion...'
            "
            autofocus
            @keyup.enter="aceptar()"
          />
        </div>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-else class="hint">{{ loading ? 'Buscando...' : `${items.length} resultado(s)` }}</p>

        <div ref="gridWrap" class="grid-wrap">
          <table class="entidad-grid">
            <thead>
              <tr>
                <th class="col-ind"></th>
                <th>Codigo</th>
                <th>
                  {{
                    entidad === 'articulos' || entidad === 'puestos-trabajo'
                      ? 'Descripcion'
                      : entidad === 'formas-pago' ||
                          entidad === 'cuentas' ||
                          entidad === 'cuentas-banco' ||
                          entidad === 'bancos'
                        ? 'Descripcion'
                      : entidad === 'trabajadores'
                        ? 'Nombre'
                        : 'Razon social'
                  }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(item, i) in items"
                :key="item.codigo"
                :class="{ selected: i === indice }"
                @click="seleccionarIndice(i)"
                @dblclick="aceptar(i)"
              >
                <td class="col-ind">{{ i === indice ? '>' : '' }}</td>
                <td>{{ item.codigo }}</td>
                <td>{{ item.etiqueta }}</td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="3">{{ loading ? 'Buscando...' : 'Sin resultados' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="modal-footer">
          <button type="button" class="btn-primary" :disabled="!items.length" @click="aceptar()">
            Seleccionar
          </button>
          <button type="button" @click="emit('cerrar')">Cancelar</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 5000;
  padding: 1rem;
}

.modal {
  width: min(640px, 100%);
  max-height: min(80vh, 640px);
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  padding: 0.75rem;
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.2);
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.modal-header h3 {
  margin: 0;
  font-size: 1rem;
}

.btn-cerrar {
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  padding: 0.25rem 0.55rem;
  cursor: pointer;
  font-size: 0.8rem;
}

.buscar-bar {
  display: flex;
  gap: 0.35rem;
}

.buscar-bar input {
  flex: 1;
  padding: 0.4rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 0.85rem;
}

.grid-wrap {
  overflow: auto;
  flex: 1;
  min-height: 12rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
}

.entidad-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.entidad-grid th,
.entidad-grid td {
  border: 1px solid #cbd5e1;
  padding: 0.2rem 0.35rem;
}

.entidad-grid th {
  background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
  font-weight: 600;
  text-align: center;
  position: sticky;
  top: 0;
}

.col-ind {
  width: 1.5rem;
  text-align: center;
  color: #1e40af;
  font-weight: 700;
  background: #f8fafc;
}

.entidad-grid tbody tr {
  cursor: pointer;
}

.entidad-grid tbody tr.selected {
  background: #dbeafe;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
}

.modal-footer button {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.8rem;
}

.btn-primary {
  background: #2563eb !important;
  border-color: #1d4ed8 !important;
  color: #fff !important;
}

.btn-primary:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.hint,
.error {
  margin: 0;
  font-size: 0.85rem;
}

.hint {
  color: #64748b;
}

.error {
  color: #b91c1c;
}
</style>
