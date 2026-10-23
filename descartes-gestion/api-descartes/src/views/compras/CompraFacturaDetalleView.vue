<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { obtenerFacturaCompra } from '@/api/compras'
import type { FacturaCompraDetalle } from '@/types/compras'
import { extractApiError } from '@/composables/extractApiError'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'

const route = useRoute()
const router = useRouter()

const pathInstancia = route.fullPath

function esEstaInstanciaActiva(): boolean {
  return route.fullPath === pathInstancia
}

const loading = ref(false)
const error = ref<string | null>(null)
const ficha = ref<FacturaCompraDetalle | null>(null)

const titulo = computed(() => {
  if (!ficha.value) return 'Factura de proveedor'
  return `Factura ${ficha.value.factura}`
})

type VtoRow = {
  n: number
  fecha: string
  importe: number
  estado: string
}

const vencimientos = computed((): VtoRow[] => {
  const f = ficha.value
  if (!f) return []
  const fechas = [
    f.fechaVto1,
    f.fechaVto2,
    f.fechaVto3,
    f.fechaVto4,
    f.fechaVto5,
    f.fechaVto6,
  ]
  const importes = [
    f.importeVto1,
    f.importeVto2,
    f.importeVto3,
    f.importeVto4,
    f.importeVto5,
    f.importeVto6,
  ]
  const estados = [
    f.estadoVto1,
    f.estadoVto2,
    f.estadoVto3,
    f.estadoVto4,
    f.estadoVto5,
    f.estadoVto6,
  ]
  const rows: VtoRow[] = []
  for (let i = 0; i < 6; i++) {
    const fecha = fmtFecha(fechas[i])
    const importe = Number(importes[i] ?? 0)
    const estado = (estados[i] || '').trim() || '—'
    if (!fecha && Math.abs(importe) < 0.0000001) continue
    rows.push({ n: i + 1, fecha: fecha || '—', importe, estado })
  }
  return rows
})

const bases = computed(() => {
  const f = ficha.value
  if (!f) return []
  return [
    { n: 1, base: Number(f.baseImp1 ?? 0), iva: Number(f.pjeIva1 ?? 0), rec: Number(f.pjeRec1 ?? 0) },
    { n: 2, base: Number(f.baseImp2 ?? 0), iva: Number(f.pjeIva2 ?? 0), rec: Number(f.pjeRec2 ?? 0) },
    { n: 3, base: Number(f.baseImp3 ?? 0), iva: Number(f.pjeIva3 ?? 0), rec: Number(f.pjeRec3 ?? 0) },
  ].filter((b) => Math.abs(b.base) > 0.0000001 || Math.abs(b.iva) > 0.0000001)
})

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

function fmtNum(n: number | null | undefined, dec = 2) {
  return Number(n ?? 0).toFixed(dec)
}

async function cargar() {
  if (!esEstaInstanciaActiva()) return
  const factura = Number(route.params.factura ?? 0)
  if (!Number.isFinite(factura) || factura <= 0) {
    error.value = 'Factura no válida'
    ficha.value = null
    return
  }

  loading.value = true
  error.value = null
  try {
    ficha.value = await obtenerFacturaCompra(factura)
  } catch (e: unknown) {
    ficha.value = null
    error.value = extractApiError(e, 'No se pudo cargar la factura de proveedor')
  } finally {
    loading.value = false
  }
}

function volverListado() {
  router.push({ name: 'compras-facturas' })
}

onMounted(() => {
  void cargar()
})

watch(
  () => route.params.factura,
  () => {
    if (!esEstaInstanciaActiva()) return
    void cargar()
  }
)
</script>

<template>
  <section class="factura-detalle">
    <VentaToolbar
      :puede-crear="false"
      :puede-editar="false"
      :puede-eliminar="false"
      :puede-guardar="false"
      :puede-imprimir="false"
      :puede-finalizar="false"
      :puede-abonar="false"
      :puede-navegar="false"
      :modo-edicion="false"
      :bloqueado="true"
      :hay-documento="!!ficha"
      :loading="loading"
      :indice="-1"
      :total="0"
      :puede-buscar="true"
      buscar-label="Listado"
      buscar-title="Volver al listado de facturas"
      @buscar="volverListado"
    />

    <div class="head">
      <div>
        <h2>{{ titulo }}</h2>
        <p class="hint">Consulta solo lectura — bases, IVA y vencimientos</p>
      </div>
      <p class="badge-bloqueo">Solo lectura</p>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="loading && !ficha" class="msg">Cargando...</p>

    <template v-if="ficha">
      <div class="panel cabecera">
        <h3>Cabecera</h3>
        <div class="grid-cab">
          <label>
            Factura
            <input :value="ficha.factura" readonly />
          </label>
          <label>
            Su factura
            <input :value="ficha.suFactura || ''" readonly />
          </label>
          <label>
            Fecha
            <input :value="fmtFecha(ficha.fecha)" readonly />
          </label>
          <label>
            Estado
            <input :value="ficha.estado || ''" readonly />
          </label>
          <label>
            Proveedor
            <input :value="ficha.proveedor || ''" readonly />
          </label>
          <label class="span-2">
            Razón social
            <input :value="ficha.razonSocial || ''" readonly />
          </label>
          <label>
            Forma pago
            <input :value="ficha.fpago || ''" readonly />
          </label>
        </div>
      </div>

      <div class="panel">
        <h3>Bases e IVA</h3>
        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-n">#</th>
                <th class="num">Base imponible</th>
                <th class="num">% IVA</th>
                <th class="num">% Recargo</th>
                <th class="num">Cuota IVA</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in bases" :key="b.n">
                <td class="col-n">{{ b.n }}</td>
                <td class="num">{{ fmtNum(b.base) }}</td>
                <td class="num">{{ fmtNum(b.iva) }}</td>
                <td class="num">{{ fmtNum(b.rec) }}</td>
                <td class="num">{{ fmtNum(b.base * b.iva / 100) }}</td>
              </tr>
              <tr v-if="bases.length === 0">
                <td colspan="5">Sin bases</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <h3>Vencimientos</h3>
        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-n">#</th>
                <th>Fecha</th>
                <th class="num">Importe</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in vencimientos" :key="v.n">
                <td class="col-n">{{ v.n }}</td>
                <td>{{ v.fecha }}</td>
                <td class="num">{{ fmtNum(v.importe) }}</td>
                <td>{{ v.estado }}</td>
              </tr>
              <tr v-if="vencimientos.length === 0">
                <td colspan="4">Sin vencimientos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.factura-detalle h2 {
  margin: 0 0 0.25rem;
}
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}
.hint {
  margin: 0;
  color: #64748b;
  font-size: 0.85rem;
}
.badge-bloqueo {
  margin: 0;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  background: #fef3c7;
  color: #92400e;
  font-size: 0.8rem;
  font-weight: 600;
}
.error {
  color: #b91c1c;
}
.msg {
  color: #475569;
}

.panel {
  margin-bottom: 0.85rem;
  padding: 0.75rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
}
.panel h3 {
  margin: 0 0 0.55rem;
  font-size: 0.9rem;
  color: #0f172a;
}

.grid-cab {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.45rem 0.65rem;
}
.grid-cab label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.72rem;
  color: #475569;
}
.grid-cab input {
  padding: 0.3rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font: inherit;
  background: #f8fafc;
  color: #0f172a;
  width: 100%;
  box-sizing: border-box;
}
.grid-cab .span-2 {
  grid-column: span 2;
}

.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.3rem 0.35rem;
  text-align: left;
  vertical-align: middle;
}
th {
  background: #f1f5f9;
  white-space: nowrap;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-n {
  width: 2.5rem;
}

@media (max-width: 900px) {
  .grid-cab {
    grid-template-columns: 1fr 1fr;
  }
  .grid-cab .span-2 {
    grid-column: 1 / -1;
  }
}
</style>
