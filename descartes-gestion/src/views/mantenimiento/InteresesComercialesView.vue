<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import {
  clonarInteresComercial,
  interesComercialVacio,
  payloadInteresComercial,
  validarInteresComercial,
  type InteresComercialFila,
} from '@/config/intereses-comerciales-columns'
import InteresesComercialesGrid from '@/components/intereses-comerciales/InteresesComercialesGrid.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'

const MODULO = 'intereses-comerciales'
const ENTIDAD = 'intereses-comerciales'
const FILTER_KEYS = ['codigo', 'descripcion']

const { puede } = usePermisos()
const { items, loading, error, listar, crear, actualizar, eliminar } = useMantenimiento(() => ENTIDAD)

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))
const soloLectura = computed(() => !puedeCrear.value && !puedeEditar.value)

const filasTodas = ref<InteresComercialFila[]>([])
const filaNuevaDraft = ref<InteresComercialFila>(interesComercialVacio())
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const indiceSeleccionado = ref(0)
const mensaje = ref<string | null>(null)

const filas = computed<InteresComercialFila[]>(() => {
  const filtradas = aplicarFiltrosColumnas(filasTodas.value, filtros.value) as InteresComercialFila[]
  if (!puedeCrear.value) return filtradas
  return [...filtradas, filaNuevaDraft.value]
})

watch(filas, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)
const puedeMostrarGuardar = computed(() => puedeCrear.value || puedeEditar.value)
const puedeGuardar = computed(() => {
  const fila = filaSeleccionada.value
  if (!fila) return false
  if (fila._nuevo) return puedeCrear.value
  return puedeEditar.value
})
const puedeMostrarEliminar = computed(
  () => puedeEliminar.value && filaSeleccionada.value && !filaSeleccionada.value._nuevo
)

function quitarFilaNueva() {
  filaNuevaDraft.value = interesComercialVacio()
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar() {
  mensaje.value = null
  await listar({ page: 1, pageSize: 500 })
  filasTodas.value = items.value.map(clonarInteresComercial)
  filaNuevaDraft.value = interesComercialVacio()
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}

const {
  confirmOpen,
  confirmMessage,
  solicitarEliminar,
  confirmarEliminar,
  cancelarEliminar,
} = useEliminarFilaGrid({
  puedeEliminar,
  filaSeleccionada,
  eliminarApi: eliminar,
  recargar: cargar,
  quitarFilaNueva,
  setMensaje: (msg) => {
    mensaje.value = msg
  },
  etiquetaEntidad: 'el interes comercial',
  mensajeExito: 'Interes comercial eliminado',
})

function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function actualizarFila(_index: number, fila: InteresComercialFila) {
  if (fila._nuevo) {
    filaNuevaDraft.value = { ...fila }
    return
  }
  const codigo = String(fila.codigo ?? '')
  filasTodas.value = filasTodas.value.map((f) =>
    String(f.codigo) === codigo ? { ...fila, _nuevo: false } : f
  )
}

async function onGuardar() {
  const fila = filaSeleccionada.value
  if (!fila) return

  const errorValidacion = validarInteresComercial(fila)
  if (errorValidacion) {
    mensaje.value = errorValidacion
    return
  }

  try {
    const payload = payloadInteresComercial(fila)
    if (fila._nuevo) {
      await crear(payload)
      mensaje.value = 'Interes comercial creado'
    } else {
      await actualizar(String(fila.codigo), payload)
      mensaje.value = 'Interes comercial actualizado'
    }
    indiceSeleccionado.value = 0
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el interes comercial')
  }
}

function onListado() {
  window.print()
}
</script>

<template>
  <section class="intereses-view">
    <h2>Intereses comerciales</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver intereses comerciales.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <div class="toolbar toolbar--half">
        <button type="button" class="tool-btn" @click="onListado">Listado</button>

        <div class="toolbar-spacer"></div>

        <button
          v-if="puedeMostrarGuardar"
          type="button"
          class="tool-btn primary"
          :disabled="loading || !puedeGuardar"
          @click="onGuardar"
        >
          Guardar
        </button>
        <button
          v-if="puedeMostrarEliminar"
          type="button"
          class="tool-btn danger"
          :disabled="loading"
          @click="solicitarEliminar"
        >
          Eliminar
        </button>
      </div>

      <InteresesComercialesGrid
        :filas="filas"
        :indice-seleccionado="indiceSeleccionado"
        :filterable-keys="FILTER_KEYS"
        v-model:filters="filtros"
        :readonly="soloLectura"
        :loading="loading"
        @seleccionar="seleccionar"
        @actualizar="actualizarFila"
      />

      <p class="hint">
        Filtra por <strong>Codigo</strong> y <strong>Descripcion</strong> con el embudo. Seleccione una fila y edite en
        la rejilla. La fila * es para alta nueva.
      </p>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar interes comercial"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
    </template>
  </section>
</template>

<style scoped>
.intereses-view h2 {
  margin: 0 0 0.75rem;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
  padding: 0.5rem;
  background: linear-gradient(180deg, #f8fafc 0%, #e5e7eb 100%);
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  margin-bottom: 0.5rem;
}

.toolbar--half {
  width: 50%;
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

.tool-btn.danger {
  color: #b91c1c;
  border-color: #fecaca;
}

.tool-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.msg {
  color: #047857;
}

.error {
  color: #b91c1c;
}

.hint {
  margin: 0.5rem 0 0;
  font-size: 0.8rem;
  color: #64748b;
}

@media print {
  .toolbar,
  .hint,
  h2 {
    display: none;
  }
}
</style>
