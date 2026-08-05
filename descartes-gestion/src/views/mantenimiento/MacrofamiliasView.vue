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

  clonarMacrofamilia,

  macrofamiliaVacia,

  payloadMacrofamilia,

  validarMacrofamilia,

  type MacrofamiliaFila,

} from '@/config/macrofamilias-columns'

import MacrofamiliasGrid from '@/components/macrofamilias/MacrofamiliasGrid.vue'

import ConfirmDialog from '@/components/common/ConfirmDialog.vue'

import ToolIcon from '@/components/common/ToolIcon.vue'

import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'



const MODULO = 'macrofamilias'

const ENTIDAD = 'macrofamilias'

const FILTER_KEYS = ['codigo', 'descripcion']



const { puede } = usePermisos()

const { items, loading, error, listar, crear, actualizar, eliminar } = useMantenimiento(() => ENTIDAD)



const puedeCrear = computed(() => puede(MODULO, 'crear'))

const puedeEditar = computed(() => puede(MODULO, 'editar'))

const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))

const puedeVer = computed(() => puede(MODULO, 'ver'))

const soloLectura = computed(() => !puedeCrear.value && !puedeEditar.value)



const filasTodas = ref<MacrofamiliaFila[]>([])

const filaNuevaDraft = ref<MacrofamiliaFila>(macrofamiliaVacia())

const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))

const indiceSeleccionado = ref(0)

const mensaje = ref<string | null>(null)



const filas = computed<MacrofamiliaFila[]>(() => {

  const filtradas = aplicarFiltrosColumnas(filasTodas.value, filtros.value) as MacrofamiliaFila[]

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

  filaNuevaDraft.value = macrofamiliaVacia()

  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))

}



onMounted(async () => {

  if (!puedeVer.value) return

  await cargar()

})



async function cargar() {

  mensaje.value = null

  await listar({ page: 1, pageSize: 500 })

  filasTodas.value = items.value.map(clonarMacrofamilia)

  filaNuevaDraft.value = macrofamiliaVacia()

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

  etiquetaEntidad: 'la macrofamilia',

  mensajeExito: 'Macrofamilia eliminada',

})



function seleccionar(index: number) {

  indiceSeleccionado.value = index

}



function actualizarFila(_index: number, fila: MacrofamiliaFila) {

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



  const errorValidacion = validarMacrofamilia(fila)

  if (errorValidacion) {

    mensaje.value = errorValidacion

    return

  }



  try {

    const payload = payloadMacrofamilia(fila)

    if (fila._nuevo) {

      await crear(payload)

      mensaje.value = 'Macrofamilia creada'

    } else {

      await actualizar(String(fila.codigo), payload)

      mensaje.value = 'Macrofamilia actualizada'

    }

    indiceSeleccionado.value = 0

    await cargar()

  } catch (e: unknown) {

    mensaje.value = extractApiError(e, 'No se pudo guardar la macrofamilia')

  }

}



function onListado() {

  window.print()

}

</script>



<template>

  <section class="macrofamilias-view">

    <h2>Macrofamilias</h2>



    <p v-if="!puedeVer" class="error">No tiene permiso para ver macrofamilias.</p>



    <template v-else>

      <p v-if="mensaje" class="msg">{{ mensaje }}</p>

      <p v-if="error" class="error">{{ error }}</p>



      <div class="toolbar toolbar--half">

        <button type="button" class="tool-btn" @click="onListado">

          <ToolIcon name="listado" />

          <span>Listado</span>

        </button>



        <div class="toolbar-spacer"></div>



        <button

          v-if="puedeMostrarGuardar"

          type="button"

          class="tool-btn primary"

          :disabled="loading || !puedeGuardar"

          @click="onGuardar"

        >

          <ToolIcon name="guardar" />

          <span>Guardar</span>

        </button>

        <button

          v-if="puedeMostrarEliminar"

          type="button"

          class="tool-btn danger"

          :disabled="loading"

          @click="solicitarEliminar"

        >

          <ToolIcon name="borrar" />

          <span>Eliminar</span>

        </button>

      </div>



      <MacrofamiliasGrid

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

        title="Eliminar macrofamilia"

        :message="confirmMessage"

        @confirm="confirmarEliminar"

        @cancel="cancelarEliminar"

      />

    </template>

  </section>

</template>



<style scoped>

.macrofamilias-view h2 {

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

  display: flex;

  flex-direction: column;

  align-items: center;

  gap: 0.15rem;

  min-width: 4.25rem;

  padding: 0.35rem 0.75rem;

  border: 1px solid #94a3b8;

  border-radius: 8px;

  background: #fff;

  font-size: 0.8rem;

  cursor: pointer;

  color: #1e293b;

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

