<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ejecutarRetrocesoFactura, previewRetrocesoFactura } from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturasRetrocesoPreview, FacturasRetrocesoResponse } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

type Opt = { value: string; label: string }

const router = useRouter()
const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()

const loadingOpts = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const tiendas = ref<Opt[]>([])
const preview = ref<FacturasRetrocesoPreview | null>(null)
const resultado = ref<FacturasRetrocesoResponse | null>(null)
const facturaInput = ref<HTMLInputElement | null>(null)

const form = ref({
  empresa: '',
  facturaTipo: 'F',
  /** Texto: type=number + '' tras rectificar deja el input “muerto” en algunos navegadores. */
  factura: '',
})

async function enfocarFactura() {
  await nextTick()
  facturaInput.value?.focus()
  facturaInput.value?.select()
}

async function nuevaConsulta() {
  resultado.value = null
  preview.value = null
  error.value = null
  mensaje.value = null
  form.value.factura = ''
  await enfocarFactura()
}

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
  const num = Number(String(form.value.factura).trim())
  if (!Number.isFinite(num) || num <= 0) {
    error.value = 'Indique el nº de factura (o de albarán ya facturado)'
    await enfocarFactura()
    return
  }

  loading.value = true
  error.value = null
  mensaje.value = null
  resultado.value = null
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
      const desdeAlb =
        preview.value.resueltoDesdeAlbaran != null
          ? ` (desde albarán ${preview.value.resueltoDesdeAlbaran})`
          : ''
      mensaje.value = `Factura ${preview.value.facturaTipo}-${preview.value.factura}${desdeAlb} · ${preview.value.importe.toFixed(2)} € · se generará abono de ${(-preview.value.importe).toFixed(2)} €`
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar la factura')
  } finally {
    loading.value = false
  }
}

async function confirmar() {
  if (!puede('facturacion-retroceso', 'crear')) {
    error.value = 'Sin permiso para rectificar'
    return
  }
  if (!preview.value || !preview.value.puedeRetroceder) {
    error.value = 'Consulte primero una factura válida'
    return
  }
  if (
    !window.confirm(
      `¿Crear abono rectificativo de la factura ${preview.value.facturaTipo}-${preview.value.factura}?\n\n` +
        `• La factura original se conserva (sin saltos de numeración).\n` +
        `• Se crea un abono (A) por ${(-preview.value.importe).toFixed(2)} €.\n` +
        `• Los albaranes siguen ligados a la factura original.`
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
    resultado.value = result
    mensaje.value = result.mensaje
    preview.value = null
    form.value.factura = ''
    await enfocarFactura()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear la rectificativa')
  } finally {
    saving.value = false
  }
}

function irImpresion() {
  router.push('/facturacion/impresion')
}

function irDiario() {
  router.push('/facturacion/diario')
}

onMounted(async () => {
  await cargarOpciones()
  await enfocarFactura()
})
</script>

<template>
  <section class="page">
    <div class="toolbar">
      <div>
        <h2>Retroceso / rectificativa</h2>
        <p class="hint">
          Emite un <strong>abono (factura rectificativa)</strong> que anula la factura. La original
          no se borra. Puede indicar el <strong>nº de factura</strong> o el de un
          <strong>albarán ya facturado</strong>.
        </p>
      </div>
    </div>

    <form class="form" @submit.prevent="consultar">
      <label>
        <span>Tipo</span>
        <select v-model="form.facturaTipo" :disabled="loading || saving">
          <option value="F">Factura (F)</option>
        </select>
      </label>
      <label>
        <span>Tienda</span>
        <select v-model="form.empresa" :disabled="loadingOpts || loading || saving" required>
          <option value="">—</option>
          <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.value }}</option>
        </select>
      </label>
      <label>
        <span>Nº factura o albarán</span>
        <input
          ref="facturaInput"
          v-model="form.factura"
          type="text"
          inputmode="numeric"
          pattern="[0-9]*"
          autocomplete="off"
          placeholder="Ej. 26000440"
          title="Nº de factura F, o nº de albarán ya facturado"
          :disabled="loading || saving"
        />
      </label>
      <button type="submit" class="btn" :disabled="loading || loadingOpts || saving">
        {{ loading ? 'Consultando…' : 'Consultar' }}
      </button>
      <button type="button" class="btn" :disabled="loading || saving" @click="nuevaConsulta">
        Nueva
      </button>
    </form>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>

    <div v-if="resultado?.abono" class="post-ok">
      <p class="ok">
        Abono creado: <strong>{{ resultado.abono.facturaTipo }}-{{ resultado.abono.factura }}</strong>
        ({{ resultado.abono.importe.toFixed(2) }} €) · Original
        {{ resultado.facturaTipo }}-{{ resultado.factura }} conservada.
      </p>
      <div class="post-actions">
        <button type="button" class="btn" @click="nuevaConsulta">Rectificar otra</button>
        <button type="button" class="btn" @click="irDiario">Ver diario</button>
        <button type="button" class="btn primary" @click="irImpresion">Ir a impresión</button>
      </div>
    </div>

    <div v-if="preview" class="preview">
      <h3>Vista previa</h3>
      <p v-if="preview.resueltoDesdeAlbaran" class="aviso info">
        Resuelto desde albarán {{ preview.resueltoDesdeAlbaran }} → factura
        {{ preview.facturaTipo }}-{{ preview.factura }}
      </p>
      <dl>
        <div><dt>Factura</dt><dd>{{ preview.facturaTipo }}-{{ preview.factura }}</dd></div>
        <div><dt>Fecha</dt><dd>{{ preview.fecha }}</dd></div>
        <div><dt>Cliente</dt><dd>{{ preview.cliente }} — {{ preview.razonSocial }}</dd></div>
        <div><dt>NIF</dt><dd>{{ preview.nif }}</dd></div>
        <div><dt>Importe</dt><dd>{{ preview.importe.toFixed(2) }} €</dd></div>
        <div><dt>Estado</dt><dd>{{ preview.estado || '—' }}</dd></div>
        <div>
          <dt>Abono a crear</dt>
          <dd>A (importe {{ (-preview.importe).toFixed(2) }} €)</dd>
        </div>
      </dl>

      <p v-if="preview.avisoRectificativa" class="aviso info">{{ preview.avisoRectificativa }}</p>
      <p v-if="preview.avisoCtb" class="aviso">{{ preview.avisoCtb }}</p>
      <p v-if="preview.bloqueada" class="aviso">{{ preview.mensajeBloqueo }}</p>

      <h4>Albaranes de la factura ({{ preview.totales.albaranes }}) — no se liberan</h4>
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
        {{ saving ? 'Creando abono…' : 'Confirmar rectificativa' }}
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
.btn.primary {
  background: #1e40af;
  border-color: #1e40af;
  color: #fff;
}
.btn.danger {
  background: #b91c1c;
  border-color: #b91c1c;
  color: #fff;
  margin-top: 0.75rem;
}
.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.error {
  color: #b91c1c;
  margin: 0;
}
.ok {
  color: #047857;
  margin: 0;
}
.post-ok {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem;
  border: 1px solid #a7f3d0;
  border-radius: 8px;
  background: #ecfdf5;
}
.post-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.preview {
  padding: 0.85rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
}
.preview h3,
.preview h4 {
  margin: 0 0 0.5rem;
}
.preview dl {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
  gap: 0.35rem 1rem;
  margin: 0 0 0.75rem;
}
.preview dt {
  font-size: 0.72rem;
  color: #64748b;
}
.preview dd {
  margin: 0;
  font-weight: 600;
}
.aviso {
  margin: 0 0 0.75rem;
  padding: 0.5rem 0.65rem;
  background: #fff7ed;
  border: 1px solid #fdba74;
  border-radius: 6px;
  color: #9a3412;
  font-size: 0.85rem;
}
.aviso.info {
  background: #eff6ff;
  border-color: #93c5fd;
  color: #1e40af;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}
th,
td {
  padding: 0.35rem 0.5rem;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
}
th {
  background: #f1f5f9;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.empty {
  color: #64748b;
  font-size: 0.85rem;
}
</style>
