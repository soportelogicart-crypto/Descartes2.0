<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { emitirVale, liquidarVale, listarVales } from '@/api/ventas'
import type { Vale } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import ListPagination from '@/components/common/ListPagination.vue'

const { puede } = usePermisos()
const puedeCrear = computed(() => puede('ventas-vales', 'crear'))
const puedeEditar = computed(() => puede('ventas-vales', 'editar'))

const loading = ref(false)
const error = ref<string | null>(null)
const msg = ref<string | null>(null)
const items = ref<Vale[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const estado = ref('pendientes')

const emitir = ref({ empresa: '001', cliente: '', importe: 0, fechaCaducidad: '', formaPago: '' })
const liquidarForm = ref({ fechaLiquidacion: new Date().toISOString().slice(0, 10), tipoLiquidacion: 'C' })

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const data = await listarVales({
      estado: estado.value,
      page: page.value,
      pageSize: pageSize.value,
    })
    items.value = data.items
    total.value = data.total
    page.value = data.page
    pageSize.value = data.pageSize
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar vales')
  } finally {
    loading.value = false
  }
}

function onEstadoChange() {
  page.value = 1
  void cargar()
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

async function onEmitir() {
  msg.value = null
  error.value = null
  try {
    await emitirVale({
      empresa: emitir.value.empresa,
      cliente: emitir.value.cliente,
      importe: Number(emitir.value.importe),
      fechaCaducidad: emitir.value.fechaCaducidad || undefined,
      formaPago: emitir.value.formaPago || undefined,
    })
    msg.value = 'Vale emitido'
    page.value = 1
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo emitir')
  }
}

async function onLiquidar(v: Vale) {
  if (!puedeEditar.value) return
  msg.value = null
  error.value = null
  try {
    await liquidarVale(v.empresa, v.codigo, { ...liquidarForm.value })
    msg.value = `Vale ${v.codigo} liquidado`
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo liquidar')
  }
}

onMounted(cargar)
</script>

<template>
  <section>
    <h2>Liquidacion de Vales</h2>

    <div class="toolbar">
      <label>
        Estado
        <select v-model="estado" @change="onEstadoChange">
          <option value="pendientes">Pendientes</option>
          <option value="liquidados">Liquidados</option>
          <option value="todos">Todos</option>
        </select>
      </label>
      <label>Fecha liq. <input v-model="liquidarForm.fechaLiquidacion" type="date" /></label>
      <label>Tipo liq. <input v-model="liquidarForm.tipoLiquidacion" maxlength="1" /></label>
    </div>

    <form v-if="puedeCrear" class="emitir" @submit.prevent="onEmitir">
      <h3>Emitir vale</h3>
      <label>Empresa <input v-model="emitir.empresa" maxlength="3" required /></label>
      <label>Cliente <input v-model="emitir.cliente" required /></label>
      <label>Importe <input v-model.number="emitir.importe" type="number" step="0.01" min="0.01" required /></label>
      <label>Caducidad <input v-model="emitir.fechaCaducidad" type="date" /></label>
      <label>Forma pago <input v-model="emitir.formaPago" maxlength="2" /></label>
      <button type="submit">Emitir</button>
    </form>

    <p v-if="msg" class="msg">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>

    <div class="grid-wrap">
      <table>
        <thead>
          <tr>
            <th>Codigo</th>
            <th>Cliente</th>
            <th>Importe</th>
            <th>Caducidad</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in items" :key="`${v.empresa}-${v.codigo}`">
            <td>{{ v.codigo }}</td>
            <td>{{ v.cliente }}</td>
            <td class="num">{{ v.importe.toFixed(2) }}</td>
            <td>{{ v.fechaCaducidad ?? '—' }}</td>
            <td>
              <span v-if="v.liquidado">Liquidado</span>
              <span v-else-if="v.caducado">Caducado</span>
              <span v-else>Pendiente</span>
            </td>
            <td>
              <button
                v-if="puedeEditar && !v.liquidado"
                type="button"
                :disabled="v.caducado || loading"
                @click="onLiquidar(v)"
              >
                Liquidar
              </button>
            </td>
          </tr>
          <tr v-if="!loading && !items.length"><td colspan="6">Sin vales</td></tr>
        </tbody>
      </table>
    </div>
    <ListPagination
      :page="page"
      :page-size="pageSize"
      :total="total"
      :loading="loading"
      @update:page="onPage"
      @update:page-size="onPageSize"
    />
  </section>
</template>

<style scoped>
.toolbar, .emitir { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end; margin: 0.75rem 0; }
.toolbar label, .emitir label { display: flex; flex-direction: column; font-size: 0.75rem; gap: 0.15rem; }
input, select { padding: 0.3rem 0.4rem; border: 1px solid #94a3b8; border-radius: 4px; }
button { padding: 0.35rem 0.75rem; cursor: pointer; }
.grid-wrap { overflow: auto; border: 1px solid #94a3b8; border-radius: 4px; }
table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
th, td { border-bottom: 1px solid #e2e8f0; padding: 0.3rem 0.45rem; }
th { background: #f1f5f9; }
.num { text-align: right; }
.error { color: #b91c1c; }
.msg { color: #166534; }
</style>
