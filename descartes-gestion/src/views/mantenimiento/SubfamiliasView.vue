<script setup lang="ts">

import { computed, onMounted, ref, watch } from 'vue'

import { api } from '@/api/client'

import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'

import { usePermisos } from '@/composables/usePermisos'

import {

  aplicarFiltrosColumnas,

  filtrosIniciales,

  type ColumnFilter,

} from '@/composables/useGridColumnFilters'

import {

  clonarSubfamilia,

  nombreFamilia,

  payloadSubfamilia,

  subfamiliaVacia,

  validarSubfamilia,

  type SubfamiliaFila,

} from '@/config/subfamilias-columns'

import SubfamiliasGrid from '@/components/subfamilias/SubfamiliasGrid.vue'

import ConfirmDialog from '@/components/common/ConfirmDialog.vue'

import ListPagination from '@/components/common/ListPagination.vue'

import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'



const MODULO = 'subfamilias'

const ENTIDAD = 'subfamilias'

const FILTER_KEYS = [

  'codigo',

  'descripcion',

  'familiaCodigo',

  'familiaNombre',

  'cuentaCtb',

  'idWeb',

  'idWeb2',

  'idWeb3',

  'idWeb4',

  'ctaTraspasoEntrada',

  'ctaTraspasoSalida',

]



const { puede } = usePermisos()

const { items, total, page, pageSize, loading, error, listar, crear, actualizar, eliminar } = useMantenimiento(() => ENTIDAD)

pageSize.value = 50



const puedeCrear = computed(() => puede(MODULO, 'crear'))

const puedeEditar = computed(() => puede(MODULO, 'editar'))

const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))

const puedeVer = computed(() => puede(MODULO, 'ver'))

const soloLectura = computed(() => !puedeCrear.value && !puedeEditar.value)



const filasTodas = ref<SubfamiliaFila[]>([])

const filaNuevaDraft = ref<SubfamiliaFila>(subfamiliaVacia())

const familiaOpciones = ref<{ value: string; label: string }[]>([])

const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))

const indiceSeleccionado = ref(0)

const mensaje = ref<string | null>(null)



const filas = computed<SubfamiliaFila[]>(() => {

  const filtradas = aplicarFiltrosColumnas(filasTodas.value, filtros.value) as SubfamiliaFila[]

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

  filaNuevaDraft.value = subfamiliaVacia()

  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))

}



onMounted(async () => {

  if (!puedeVer.value) return

  await cargarFamilias()

  await cargar()

})



async function cargarFamilias() {

  const { data } = await api.get('/api/mantenimiento/familias', { params: { pageSize: 500 } })

  familiaOpciones.value = (data.items ?? []).map((f: { codigo: string; descripcion: string }) => ({

    value: String(f.codigo).trim(),

    label: `${String(f.codigo).trim()} - ${f.descripcion}`,

  }))

}



function mapFilasDesdeApi() {

  filasTodas.value = items.value.map((item) => {

    const fila = clonarSubfamilia(item)

    fila.familiaNombre = nombreFamilia(fila.familiaCodigo ?? '', familiaOpciones.value)

    return fila

  })

}



async function cargar() {

  mensaje.value = null

  await listar({ page: page.value, pageSize: pageSize.value })

  mapFilasDesdeApi()

  filaNuevaDraft.value = subfamiliaVacia()

  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))

}



function onPage(p: number) {

  page.value = p

  void cargar()

}



function onPageSize(n: number) {

  pageSize.value = n

  page.value = 1

  void cargar()

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

  etiquetaEntidad: 'la subfamilia',

  mensajeExito: 'Subfamilia eliminada',

})



function seleccionar(index: number) {

  indiceSeleccionado.value = index

}



function actualizarFila(_index: number, fila: SubfamiliaFila) {

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



  const errorValidacion = validarSubfamilia(fila)

  if (errorValidacion) {

    mensaje.value = errorValidacion

    return

  }



  try {

    const payload = payloadSubfamilia(fila)

    if (fila._nuevo) {

      await crear(payload)

      mensaje.value = 'Subfamilia creada'

    } else {

      await actualizar(String(fila.codigo), payload)

      mensaje.value = 'Subfamilia actualizada'

    }

    indiceSeleccionado.value = 0

    await cargar()

  } catch (e: unknown) {

    mensaje.value = extractApiError(e, 'No se pudo guardar la subfamilia')

  }

}



function onListado() {

  window.print()

}

</script>



<template>

  <section class="subfamilias-view">

    <h2>Subfamilias</h2>



    <p v-if="!puedeVer" class="error">No tiene permiso para ver subfamilias.</p>



    <template v-else>

      <p v-if="mensaje" class="msg">{{ mensaje }}</p>

      <p v-if="error" class="error">{{ error }}</p>



      <div class="toolbar">

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



      <SubfamiliasGrid

        :filas="filas"

        :indice-seleccionado="indiceSeleccionado"

        :familia-opciones="familiaOpciones"

        :filterable-keys="FILTER_KEYS"

        v-model:filters="filtros"

        :readonly="soloLectura"

        :loading="loading"

        @seleccionar="seleccionar"

        @actualizar="actualizarFila"

      />



      <ListPagination

        :page="page"

        :page-size="pageSize"

        :total="total"

        :loading="loading"

        @update:page="onPage"

        @update:page-size="onPageSize"

      />



      <p class="hint">

        Filtra columnas con el embudo. Seleccione una fila y edite en la rejilla. La fila * es para alta nueva.

      </p>



      <ConfirmDialog

        :open="confirmOpen"

        title="Eliminar subfamilia"

        :message="confirmMessage"

        @confirm="confirmarEliminar"

        @cancel="cancelarEliminar"

      />

    </template>

  </section>

</template>



<style scoped>

.subfamilias-view h2 {

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

