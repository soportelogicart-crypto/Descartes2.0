<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ejecutarRetrocesoFactura, previewRetrocesoFactura } from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturasRetrocesoPreview } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

type Opt = { value: string; label: string }

const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()

const loadingOpts = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const tiendas = ref<Opt[]>([])
const preview = ref<FacturasRetrocesoPreview | null>(null)

const form = ref({
  empresa: '',
  facturaTipo: 'F',
  factura: '' as string | number,
})

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const tiendasRes = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (tiendasRes.data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: `${codigo} — ${t.nombre ?? ''}` }
    })
    const emp = String(puestoContexto.empresaCodigo ?? '').trim()
    if (emp) form.value.empresa = emp
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar opciones')
  } finally {
    loadingOpts.value = false
  }
}

async function consultar() {
  if (!puede('facturacion-retroceso', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (!form.value.empresa.trim()) {
    error.value = 'Indique la tienda'
    return
  }
  const num = Number(form.value.factura)
  if (!Number.isFinite(num) || num <= 0) {
    error.value = 'Indique el número de factura'
    return
  }

  loading.value = true
  error.value = null
  mensaje.value = null
  preview.value = null
  try {
    preview.value = await previewRetrocesoFactura({
      empresa: form.value.empresa.trim(),
      facturaTipo: form.value.facturaTipo,
      factura: num,
    })
    if (preview.value.bloqueada) {
      error.value = preview.value.mensajeBloqueo
    } else {
      mensaje.value = `Factura ${preview.value.facturaTipo}-${preview.value.factura} · ${preview.value.importe.toFixed(2)} € · ${preview.value.totales.albaranes} albarán(es)`
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar la factura')
  } finally {
    loading.value = false
  }
}

async function confirmar() {
  if (!puede('facturacion-retroceso', 'crear')) {
    error.value = 'Sin permiso para retroceder'
    return
  }
  if (!preview.value || !preview.value.puedeRetroceder) {
    error.value = 'Consulte primero una factura válida'
    return
  }
  if (
    !window.confirm(
      `¿Retroceder la factura ${preview.value.facturaTipo}-${preview.value.factura}? Se eliminará de Facturas y liberará los albaranes. Esta acción no se puede deshacer.`
    )
  ) {
    return
  }

  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const result = await ejecutarRetrocesoFactura({
      empresa: preview.value.empresa,
      facturaTipo: preview.value.facturaTipo,
      factura: preview.value.factura,
    })
    mensaje.value = `${result.mensaje} · ${result.albaranesLiberados} albarán(es) liberado(s)`
    preview.value = null
    form.value.factura = ''
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo retroceder la factura')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
})
</script>

<template>
  <section class="page">
    <div class="toolbar">
      <div>
        <h2>Retroceso de facturas</h2>
        <p class="hint">Deshace una factura y deja los albaranes pendientes de facturar de nuevo.</p>
      </div>
    </div>

    <form class="form" @submit.prevent="consultar">
      <label>
        <span>Tipo</span>
        <select v-model="form.facturaTipo">
          <option value="F">Factura (F)</option>
          <option value="A">Abono (A)</option>
        </select>
      </label>
      <label>
        <span>Tienda</span>
        <select v-model="form.empresa" :disabled="loadingOpts" required>
          <option value="">—</option>
          <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.value }}</option>
        </select>
      </label>
      <label>
        <span>Nº factura</span>
        <input v-model="form.factura" type="number" min="1" required />
      </label>
      <button type="submit" class="btn" :disabled="loading || loadingOpts">
        {{ loading ? 'Consultando…' : 'Ejecutar' }}
      </button>
    </form>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>

    <div v-if="preview" class="preview">
      <h3>Vista previa</h3>
      <dl>
        <div><dt>Factura</dt><dd>{{ preview.facturaTipo }}-{{ preview.factura }}</dd></div>
        <div><dt>Fecha</dt><dd>{{ preview.fecha }}</dd></div>
        <div><dt>Cliente</dt><dd>{{ preview.cliente }} — {{ preview.razonSocial }}</dd></div>
        <div><dt>NIF</dt><dd>{{ preview.nif }}</dd></div>
        <div><dt>Importe</dt><dd>{{ preview.importe.toFixed(2) }} €</dd></div>
        <div><dt>Estado</dt><dd>{{ preview.estado || '—' }}</dd></div>
      </dl>

      <p v-if="preview.avisoCtb" class="aviso">{{ preview.avisoCtb }}</p>

      <h4>Albaranes vinculados ({{ preview.totales.albaranes }})</h4>
      <div v-if="preview.albaranes.length" class="grid-wrap">
        <table>
          <thead>
            <tr>
              <th>Tie.</th>
              <th>Alb.</th>
              <th>Fecha</th>
              <th>Cliente</th>
              <th class="num">Importe</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in preview.albaranes" :key="`${a.empresa}|${a.tipo}|${a.albaran}`">
              <td>{{ a.empresa }}</td>
              <td>{{ a.albaran }}</td>
              <td>{{ a.fecha }}</td>
              <td>{{ a.cliente }}</td>
              <td class="num">{{ a.importe.toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="empty">Sin albaranes enlazados.</p>

      <button
        type="button"
        class="btn danger"
        :disabled="saving || !preview.puedeRetroceder"
        @click="confirmar"
      >
        {{ saving ? 'Retrocediendo…' : 'Confirmar retroceso' }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  max-width: 52rem;
}
.toolbar h2 {
  margin: 0;
}
.hint {
  margin: 0.15rem 0 0;
  color: #64748b;
  font-size: 0.85rem;
}
.form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  align-items: end;
  padding: 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
}
.form label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
}
.form input,
.form select {
  padding: 0.35rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  min-width: 7rem;
}
.btn {
  padding: 0.4rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
}
.btn.danger {
  background: #b91c1c;
  border-color: #991b1b;
  color: #fff;
  margin-top: 0.5rem;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.preview {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 0.85rem 1rem;
  background: #fff;
}
.preview h3,
.preview h4 {
  margin: 0 0 0.5rem;
}
.preview h4 {
  margin-top: 0.85rem;
  font-size: 0.9rem;
}
dl {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
  gap: 0.35rem 1rem;
  margin: 0;
}
dl div {
  display: grid;
  gap: 0.1rem;
}
dt {
  font-size: 0.72rem;
  color: #64748b;
}
dd {
  margin: 0;
  font-size: 0.9rem;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  max-height: 16rem;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
th,
td {
  border: 1px solid #e2e8f0;
  padding: 0.25rem 0.35rem;
}
th {
  background: #f1f5f9;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.error {
  color: #b91c1c;
  font-weight: 600;
}
.ok {
  color: #047857;
  font-weight: 600;
}
.aviso {
  color: #92400e;
  background: #fef3c7;
  border: 1px solid #fbbf24;
  border-radius: 6px;
  padding: 0.5rem 0.65rem;
  font-size: 0.85rem;
}
.empty {
  color: #64748b;
  font-size: 0.85rem;
}
</style>
