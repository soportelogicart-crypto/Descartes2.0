<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { obtenerAbcVentas } from '@/api/abc-ventas'
import type { LookupEntidad, LookupItem } from '@/api/lookup'
import type { AbcVentasFiltros, AbcVentasResponse } from '@/types/abc-ventas'
import { extractApiError } from '@/composables/extractApiError'
import { useAuth } from '@/composables/useAuth'
import {
  abcFiltroSoloDigitos,
  abcVentasFiltrosRangos,
  type AbcFiltroRango,
} from '@/config/abc-ventas-filtros'
import LookupBuscarModal from '@/components/LookupBuscarModal.vue'
import {
  labelFiltro,
  t,
} from '@/i18n/abc-ventas'
import { saveAbcPreview } from '@/utils/abcPreviewStore'

const router = useRouter()
const route = useRoute()
const { user } = useAuth()

const loading = ref(false)
const error = ref<string | null>(null)
const data = ref<AbcVentasResponse | null>(null)

const codigoVendedor = computed(() => String(user.value?.codigo ?? '').trim())
const nombreVendedor = computed(() => String(user.value?.nombre ?? '').trim())
const vendedorLabel = computed(() => {
  const codigo = codigoVendedor.value
  const nombre = nombreVendedor.value
  if (!codigo) return ''
  return nombre ? `${codigo} ${nombre}` : codigo
})

const dimensionFija = computed<'vendedores' | 'clientes'>(() =>
  route.meta.dimension === 'clientes' ? 'clientes' : 'vendedores',
)
const esClientes = computed(() => dimensionFija.value === 'clientes')

const form = ref<AbcVentasFiltros>({
  dimension: dimensionFija.value,
  orden: 'margen',
  idioma: 'castellano',
  divisa: 'EU',
  iva: 'incluido',
  imArticulos: true,
  valor: 'precioMedio',
  tipoVenta: 'todos',
  fechaDesde: '',
  fechaHasta: '',
  macroFamiliaDesde: '',
  macroFamiliaHasta: '',
  familiaDesde: '',
  familiaHasta: '',
  subfamiliaDesde: '',
  subfamiliaHasta: '',
  agrupacionDesde: '',
  agrupacionHasta: '',
  articuloDesde: '',
  articuloHasta: '',
  tiendaDesde: '',
  tiendaHasta: '',
  agenteDesde: '',
  agenteHasta: '',
  representanteDesde: '',
  representanteHasta: '',
  vendedorDesde: codigoVendedor.value,
  vendedorHasta: codigoVendedor.value,
  clienteDesde: '',
  clienteHasta: '',
  proveedorDesde: '',
  proveedorHasta: '',
  seccionDesde: '',
  seccionHasta: '',
  subSeccionDesde: '',
  subSeccionHasta: '',
  actividadDesde: '',
  actividadHasta: '',
  tipoDescuentoDesde: '',
  tipoDescuentoHasta: '',
})

watch(
  dimensionFija,
  (dim) => {
    form.value.dimension = dim
    data.value = null
    error.value = null
  },
  { immediate: true },
)

/** Vendedor fijo; Agente/Representante ocultos de momento. */
const rangos = abcVentasFiltrosRangos.filter(
  (r) => !r.oculto && r.desde !== 'vendedorDesde',
)

type LookupTarget = {
  entidad: LookupEntidad
  key: string
  titulo: string
}

const lookupOpen = ref(false)
const lookupTarget = ref<LookupTarget | null>(null)

const tr = (key: string) => t(form.value.idioma, key)
const tituloPagina = computed(() =>
  esClientes.value ? tr('tituloListadoClientes') : tr('tituloListado'),
)
const tituloInforme = computed(() => (esClientes.value ? tr('abcClientes') : tr('abcVentas')))

function valorFiltro(key: string): string {
  return String((form.value as Record<string, unknown>)[key] ?? '')
}

function onFiltroInput(r: AbcFiltroRango, lado: 'desde' | 'hasta', raw: string) {
  let v = raw
  if (abcFiltroSoloDigitos(r.formato)) {
    v = v.replace(/\D+/g, '')
  }
  if (r.maxLength > 0 && v.length > r.maxLength) {
    v = v.slice(0, r.maxLength)
  }
  const key = lado === 'desde' ? r.desde : r.hasta
  ;(form.value as Record<string, unknown>)[key] = v
}

function abrirLookup(r: AbcFiltroRango, lado: 'desde' | 'hasta') {
  if (!r.lookup) return
  const key = lado === 'desde' ? r.desde : r.hasta
  lookupTarget.value = {
    entidad: r.lookup,
    key,
    titulo: `Buscar ${r.label} (${lado === 'desde' ? 'Desde' : 'Hasta'})`,
  }
  lookupOpen.value = true
}

function onLookupSeleccionar(item: LookupItem) {
  if (!lookupTarget.value) return
  let codigo = item.codigo
  const r = rangos.find(
    (x) => x.desde === lookupTarget.value?.key || x.hasta === lookupTarget.value?.key,
  )
  if (r) {
    if (abcFiltroSoloDigitos(r.formato)) {
      codigo = codigo.replace(/\D+/g, '')
    }
    if (r.maxLength > 0 && codigo.length > r.maxLength) {
      codigo = codigo.slice(0, r.maxLength)
    }
  }
  ;(form.value as Record<string, unknown>)[lookupTarget.value.key] = codigo
}

function cerrarLookup() {
  lookupOpen.value = false
  lookupTarget.value = null
}

async function generar() {
  loading.value = true
  error.value = null
  try {
    const codigo = codigoVendedor.value
    const result = await obtenerAbcVentas({
      ...form.value,
      dimension: dimensionFija.value,
      vendedorDesde: codigo,
      vendedorHasta: codigo,
    })
    data.value = result
    saveAbcPreview({
      data: result,
      titulo: tituloInforme.value,
      vendedorLabel: vendedorLabel.value,
      returnTo: String(route.name || 'home'),
    })
    const href = router.resolve({ name: 'abc-preview' }).href
    const width = Math.min(1280, Math.max(960, window.screen.availWidth - 80))
    const height = Math.min(900, Math.max(700, window.screen.availHeight - 80))
    const left = Math.max(0, Math.round((window.screen.availWidth - width) / 2))
    const top = Math.max(0, Math.round((window.screen.availHeight - height) / 2))
    const features = [
      `width=${width}`,
      `height=${height}`,
      `left=${left}`,
      `top=${top}`,
      'menubar=no',
      'toolbar=no',
      'location=no',
      'status=no',
      'resizable=yes',
      'scrollbars=yes',
    ].join(',')
    const win = window.open(href, 'abc-ventas-preview', features)
    if (win) {
      win.focus()
    } else {
      error.value = 'Permita ventanas emergentes para ver la previsualización'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el listado ABC')
    data.value = null
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="abc-page page-enter">
    <div class="toolbar no-print">
      <div class="toolbar-title">
        <button type="button" class="btn ghost" @click="router.push({ name: 'home' })">
          {{ tr('inicio') }}
        </button>
        <h2>{{ tituloPagina }}</h2>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn primary" :disabled="loading" @click="generar">
          {{ loading ? tr('generando') : tr('generar') }}
        </button>
      </div>
    </div>

    <form class="panel no-print" @submit.prevent="generar">
      <fieldset class="opciones">
        <legend>{{ tr('opciones') }}</legend>
        <label>
          {{ tr('dimension') }}
          <select v-model="form.dimension" disabled>
            <option v-if="esClientes" value="clientes">{{ tr('clientes') }}</option>
            <option v-else value="vendedores">{{ tr('vendedores') }}</option>
          </select>
        </label>
        <label>
          {{ tr('orden') }}
          <select v-model="form.orden">
            <option value="margen">{{ tr('ordenMargen') }}</option>
            <option value="importe">{{ tr('ordenImporte') }}</option>
            <option value="cantidad">{{ tr('ordenCantidad') }}</option>
            <option value="coste">{{ tr('ordenCoste') }}</option>
            <option value="vendedor">{{ tr('ordenVendedor') }}</option>
          </select>
        </label>
        <label>
          {{ tr('idioma') }}
          <select v-model="form.idioma">
            <option value="castellano">{{ tr('castellano') }}</option>
            <option value="catalan">{{ tr('catalan') }}</option>
          </select>
        </label>
        <label>
          {{ tr('divisa') }}
          <select v-model="form.divisa">
            <option value="EU">EU</option>
          </select>
        </label>
        <label>
          {{ tr('iva') }}
          <select v-model="form.iva">
            <option value="incluido">{{ tr('incluido') }}</option>
            <option value="desglosado">{{ tr('desglosado') }}</option>
          </select>
        </label>
        <label>
          {{ tr('imArticulos') }}
          <select v-model="form.imArticulos">
            <option :value="true">{{ tr('si') }}</option>
            <option :value="false">{{ tr('no') }}</option>
          </select>
        </label>
        <label>
          {{ tr('valor') }}
          <select v-model="form.valor">
            <option value="precioMedio">{{ tr('valorPrecioMedio') }}</option>
            <option value="precioMedioActual">{{ tr('valorPrecioMedioActual') }}</option>
            <option value="ultimoPrecio">{{ tr('valorUltimoPrecio') }}</option>
            <option value="sinValorTarifa">{{ tr('valorSinValorTarifa') }}</option>
          </select>
        </label>
        <label>
          {{ tr('tipoVenta') }}
          <select v-model="form.tipoVenta">
            <option value="todos">{{ tr('tipoTodos') }}</option>
            <option value="ticket">{{ tr('tipoTicket') }}</option>
            <option value="facturas">{{ tr('tipoFacturas') }}</option>
            <option value="ticketFacturas">{{ tr('tipoTicketFacturas') }}</option>
            <option value="albaranes">{{ tr('tipoAlbaranes') }}</option>
            <option value="ticketsFacturasContado">{{ tr('tipoTicketsFacturasContado') }}</option>
          </select>
        </label>
      </fieldset>

      <fieldset class="rangos">
        <legend>{{ tr('filtros') }}</legend>
        <div class="rango-row fechas">
          <span class="rango-label">{{ tr('fecha') }}</span>
          <input v-model="form.fechaDesde" type="date" required class="filtro-fecha" />
          <input v-model="form.fechaHasta" type="date" required class="filtro-fecha" />
        </div>
        <div class="rango-row vendedor-fijo">
          <span class="rango-label">{{ tr('vendedor') }}</span>
          <input
            class="filtro-input vendedor-readonly"
            type="text"
            :value="vendedorLabel"
            readonly
            tabindex="-1"
            :title="tr('vendedorSoloPropio')"
          />
        </div>
        <div v-for="r in rangos" :key="r.label" class="rango-row">
          <span class="rango-label" :title="r.formato ? `Formato: ${r.formato}` : undefined">
            {{ labelFiltro(form.idioma, r.label) }}
          </span>
          <div class="filtro-cell">
            <input
              class="filtro-input"
              :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
              type="text"
              :value="valorFiltro(r.desde)"
              :maxlength="r.maxLength"
              :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
              autocomplete="off"
              spellcheck="false"
              @input="onFiltroInput(r, 'desde', ($event.target as HTMLInputElement).value)"
            />
            <button
              v-if="r.lookup"
              type="button"
              class="btn-lupa"
              :title="`Buscar ${r.label}`"
              @click="abrirLookup(r, 'desde')"
            >
              <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                <circle
                  cx="10.5"
                  cy="10.5"
                  r="6.5"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                />
                <path
                  d="M15.5 15.5L21 21"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                />
              </svg>
            </button>
            <button v-else type="button" class="btn-lupa btn-lupa-placeholder" tabindex="-1" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                <circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2" />
                <path d="M15.5 15.5L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
              </svg>
            </button>
          </div>
          <div class="filtro-cell">
            <input
              class="filtro-input"
              :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
              type="text"
              :value="valorFiltro(r.hasta)"
              :maxlength="r.maxLength"
              :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
              autocomplete="off"
              spellcheck="false"
              @input="onFiltroInput(r, 'hasta', ($event.target as HTMLInputElement).value)"
            />
            <button
              v-if="r.lookup"
              type="button"
              class="btn-lupa"
              :title="`Buscar ${r.label}`"
              @click="abrirLookup(r, 'hasta')"
            >
              <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                <circle
                  cx="10.5"
                  cy="10.5"
                  r="6.5"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                />
                <path
                  d="M15.5 15.5L21 21"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                />
              </svg>
            </button>
            <button v-else type="button" class="btn-lupa btn-lupa-placeholder" tabindex="-1" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                <circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2" />
                <path d="M15.5 15.5L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
              </svg>
            </button>
          </div>
        </div>
      </fieldset>
    </form>

    <LookupBuscarModal
      :open="lookupOpen"
      :entidad="lookupTarget?.entidad ?? null"
      :titulo="lookupTarget?.titulo ?? 'Buscar'"
      @seleccionar="onLookupSeleccionar"
      @cerrar="cerrarLookup"
    />

    <p v-if="error" class="error no-print">{{ error }}</p>
    <p v-if="loading" class="hint no-print">{{ tr('generandoListado') }}</p>
    <p v-if="data && !loading" class="hint no-print">{{ tr('previsualizacion') }} abierta en otra ventana.</p>
  </section>
</template>

<style scoped>
.abc-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.1rem 1.35rem 1.75rem;
  min-height: 100vh;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0.85rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid rgba(15, 36, 48, 0.12);
}

.toolbar-title {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
}

.toolbar h2 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.45rem;
  letter-spacing: -0.02em;
  font-weight: 700;
}

.toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.panel {
  display: grid;
  gap: 0.85rem;
  padding: 0.95rem 1rem;
  border: 1px solid var(--line);
  background: rgba(250, 248, 244, 0.88);
}

fieldset {
  border: 1px solid var(--line);
  margin: 0;
  padding: 0.7rem 0.85rem 0.9rem;
  background: var(--surface-raised);
}

legend {
  padding: 0 0.35rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
}

.opciones {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
  gap: 0.6rem 0.8rem;
}

.opciones label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--ink-soft);
}

.opciones select {
  padding: 0.35rem 0.45rem;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  background: var(--surface-raised);
}

.opciones select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(15, 110, 102, 0.16);
}

.rangos {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.rango-row {
  display: grid;
  grid-template-columns: 7.5rem minmax(10.5rem, 12rem) minmax(10.5rem, 12rem);
  gap: 0.35rem 0.5rem;
  align-items: center;
  justify-content: start;
}

.rango-label {
  font-size: 0.78rem;
  color: var(--ink-soft);
  white-space: nowrap;
}

.filtro-cell {
  display: flex;
  align-items: stretch;
  gap: 0.2rem;
  min-width: 0;
}

.filtro-cell .filtro-input {
  flex: 1;
  min-width: 0;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 1.7rem;
  width: 1.7rem;
  padding: 0;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  background: #f3f0ea;
  color: var(--ink-soft);
  cursor: pointer;
}

.btn-lupa:hover {
  border-color: var(--accent);
  color: var(--accent);
  background: var(--accent-soft);
}

.btn-lupa-placeholder {
  opacity: 0;
  pointer-events: none;
}

.vendedor-fijo .vendedor-readonly {
  grid-column: 2 / -1;
  color: var(--ink);
  background: #ece8e0;
  cursor: default;
}

.filtro-input,
.filtro-fecha {
  width: 100%;
  box-sizing: border-box;
  padding: 0.28rem 0.4rem;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  font-size: 0.8rem;
  background: var(--surface-raised);
}

.filtro-input:focus,
.filtro-fecha:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(15, 110, 102, 0.16);
}

.filtro-input.numerico {
  font-variant-numeric: tabular-nums;
  font-family: var(--font-mono);
  text-align: right;
}

.error {
  color: var(--danger);
  margin: 0;
}

.hint {
  margin: 0;
  color: var(--muted);
  font-size: 0.875rem;
}

@media (max-width: 720px) {
  .abc-page {
    padding-inline: 0.85rem;
  }

  .rango-row {
    grid-template-columns: 1fr;
  }
}
</style>