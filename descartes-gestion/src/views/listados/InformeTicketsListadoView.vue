<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { generarInformeTickets, type InformeTicketsResult } from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { hoyIso, inicioMesIso } from '@/composables/useAtajosFecha'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useDivisasTienda } from '@/composables/useDivisasTienda'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'
import { cargarEmblemaEmpresa } from '@/composables/cargarEmblemaEmpresa'
import {
  construirHtmlInformeTicketsLegacy,
  construirPreviewInformeTicketsLegacy,
} from '@/composables/informeTicketsPlantillas'

const router = useRouter()
const { registrarReciente } = useListadosRecientes()
const { divisasTienda } = useDivisasTienda()

const FORMATOS = [
  { value: 'desglosado', label: 'Desglosado' },
  { value: 'resumido', label: 'Resumido' },
  { value: 'superResumido', label: 'Super Resumido' },
  { value: 'exportar', label: 'Exportar' },
] as const

const generando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<InformeTicketsResult | null>(null)
const logoInforme = ref('')

const form = ref({
  idioma: 'Castellano',
  divisa: 'EU',
  formato: 'desglosado',
  fechaDesde: inicioMesIso(), fechaHasta: hoyIso(),
  empresaDesde: '', empresaHasta: '',
  puestoDesde: '', puestoHasta: '',
  sesionDesde: '', sesionHasta: '',
  agenteDesde: '', agenteHasta: '',
  representanteDesde: '', representanteHasta: '',
  clienteDesde: '', clienteHasta: '',
  ticketDesde: '', ticketHasta: '',
  origenDesde: '', origenHasta: '',
  vendedorDesde: '', vendedorHasta: '',
})

watch(divisasTienda, (opciones) => {
  if (opciones.length && !opciones.some((o) => o.value === form.value.divisa)) {
    form.value.divisa = opciones[0].value
  }
})

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)

const previewHtml = computed(() =>
  resultado.value && tieneDatos.value
    ? construirPreviewInformeTicketsLegacy(resultado.value, { logoUrl: logoInforme.value })
    : '',
)

function intOpt(value: string): number | undefined {
  const n = Number.parseInt(value, 10)
  return Number.isFinite(n) && n > 0 ? n : undefined
}

function params() {
  return {
    fechaDesde: form.value.fechaDesde,
    fechaHasta: form.value.fechaHasta,
    formato: form.value.formato,
    divisa: form.value.divisa,
    empresaDesde: form.value.empresaDesde.trim() || undefined,
    empresaHasta: form.value.empresaHasta.trim() || undefined,
    puestoDesde: form.value.puestoDesde.trim() || undefined,
    puestoHasta: form.value.puestoHasta.trim() || undefined,
    sesionDesde: intOpt(form.value.sesionDesde),
    sesionHasta: intOpt(form.value.sesionHasta),
    agenteDesde: form.value.agenteDesde.trim() || undefined,
    agenteHasta: form.value.agenteHasta.trim() || undefined,
    representanteDesde: form.value.representanteDesde.trim() || undefined,
    representanteHasta: form.value.representanteHasta.trim() || undefined,
    clienteDesde: form.value.clienteDesde.trim() || undefined,
    clienteHasta: form.value.clienteHasta.trim() || undefined,
    ticketDesde: intOpt(form.value.ticketDesde),
    ticketHasta: intOpt(form.value.ticketHasta),
    origenDesde: form.value.origenDesde.trim() || undefined,
    origenHasta: form.value.origenHasta.trim() || undefined,
    vendedorDesde: form.value.vendedorDesde.trim() || undefined,
    vendedorHasta: form.value.vendedorHasta.trim() || undefined,
    soloNumerados: true,
  }
}

async function generar() {
  if (form.value.fechaDesde > form.value.fechaHasta) {
    error.value = 'La fecha desde no puede ser posterior a la fecha hasta.'
    return
  }
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    resultado.value = await generarInformeTickets(params())
    logoInforme.value = await cargarEmblemaEmpresa(resultado.value.items[0]?.empresa ?? '')
    const t = resultado.value.totales
    mensaje.value = resultado.value.items.length
      ? `${t.tickets} ticket(s) · Total ${numCsv(t.importe)} ${form.value.divisa}`
      : 'Sin tickets en el periodo y filtros actuales.'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el informe')
    resultado.value = null
    logoInforme.value = ''
  } finally {
    generando.value = false
  }
}

function exportarExcel() {
  if (!resultado.value) return
  const cab =
    form.value.formato === 'exportar'
      ? ['Fecha', 'Ticket', 'Tipo', 'Cliente', 'NIF', '% IVA', 'Base', 'IVA', 'Recargo', 'Importe']
      : form.value.formato === 'desglosado'
        ? ['Fecha', 'Tienda', 'Ticket', 'F.Pago 1', 'F.Pago 2', 'Base', 'IVA', 'Recargo', 'Importe']
        : ['Tienda', 'Fecha', 'Base', 'IVA', 'Recargo', 'Importe', 'Tickets', 'Artículos']

  let rows: (string | number | null | undefined)[][]
  if (form.value.formato === 'exportar') {
    const filas = resultado.value.exportar?.length
      ? resultado.value.exportar
      : resultado.value.items.flatMap((r) => {
          const desgloses = r.desgloseIva?.length
            ? r.desgloseIva
            : [{ pjeIva: 0, base: r.base ?? r.importe, iva: r.iva ?? 0, recargo: r.recargo ?? 0, importe: r.importe }]
          return desgloses.map((d) => ({
            fecha: r.fecha,
            numeroTicket: r.numeroTicket,
            razonSocial: r.razonSocial,
            cliente: r.cliente,
            nif: r.nif,
            pjeIva: d.pjeIva,
            base: d.base,
            iva: d.iva,
            recargo: d.recargo,
            importe: d.importe,
          }))
        })
    rows = filas.map((r) => [
      r.fecha,
      r.numeroTicket ?? '',
      'Ticket',
      r.razonSocial || r.cliente,
      r.nif ?? '',
      r.pjeIva,
      r.base,
      r.iva,
      r.recargo,
      r.importe,
    ])
  } else if (form.value.formato === 'desglosado') {
    rows = resultado.value.items.map((r) => [
      r.fecha,
      r.empresa,
      r.numeroTicket ?? '',
      r.formaPago,
      r.fpago2 ?? '',
      r.base ?? '',
      r.iva ?? '',
      r.recargo ?? '',
      r.importe,
    ])
  } else {
    rows = (resultado.value.resumenDiario ?? []).map((r) => [
      `${r.empresa} ${r.tiendaNombre}`,
      r.fecha ?? '',
      r.base,
      r.iva,
      r.recargo,
      r.importe,
      r.tickets,
      r.numeroArticulos,
    ])
  }

  descargarCsv(
    `informe-tickets-${form.value.formato}.csv`,
    [cab.map(escCsv).join(';'), ...rows.map((r) => r.map(escCsv).join(';'))],
  )
}

async function imprimir() {
  if (!resultado.value || !tieneDatos.value) return
  error.value = null
  try {
    const html = construirHtmlInformeTicketsLegacy(resultado.value, { logoUrl: logoInforme.value })
    const res = await imprimirListadoHtml({
      titulo:
        form.value.formato === 'exportar'
          ? `Diario IVA ${form.value.divisa}`
          : `Informe de Tickets ${form.value.divisa}`,
      html,
      filenameFallback: `informe-tickets-${form.value.formato}.html`,
    })
    if (res.ok) mensaje.value = res.message
    else error.value = res.message
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir el informe')
  }
}

registrarReciente('informe-tickets')
</script>

<template>
  <section class="ventas-view abc-form-view">
    <div class="head head-compact no-print">
      <div>
        <button type="button" class="volver-hub" @click="router.push('/listados')">← Listados</button>
        <h2>Informe de tickets</h2>
      </div>
      <div class="head-actions">
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="exportarExcel">Excel (CSV)</button>
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="imprimir">Imprimir</button>
      </div>
    </div>

    <div class="layout-busqueda layout-abc no-print">
      <form class="panel-filtros panel-filtros-compact" @submit.prevent="generar">
        <fieldset class="bloque-opciones opciones-fila">
          <legend>Opciones</legend>
          <label><span>Idioma</span><select v-model="form.idioma"><option>Castellano</option></select></label>
          <label>
            <span>Divisa</span>
            <select v-model="form.divisa">
              <option v-if="!divisasTienda.length" :value="form.divisa">{{ form.divisa }}</option>
              <option v-for="d in divisasTienda" :key="d.value" :value="d.value">{{ d.label }}</option>
            </select>
          </label>
          <label><span>Formato</span><select v-model="form.formato"><option v-for="f in FORMATOS" :key="f.value" :value="f.value">{{ f.label }}</option></select></label>
        </fieldset>

        <fieldset class="bloque-intervalos-col">
          <legend>Intervalos</legend>
          <div class="intervalos-col">
            <div class="rango-head"><span></span><span>Desde</span><span>Hasta</span></div>
            <div class="rango-row"><span class="rango-label">Tienda</span><span class="celda-intervalo"><input v-model="form.empresaDesde" /></span><span class="celda-intervalo"><input v-model="form.empresaHasta" /></span></div>
            <div class="rango-row"><span class="rango-label">Puesto</span><span class="celda-intervalo"><input v-model="form.puestoDesde" /></span><span class="celda-intervalo"><input v-model="form.puestoHasta" /></span></div>
            <div class="rango-row"><span class="rango-label">Sesión</span><span class="celda-intervalo"><input v-model="form.sesionDesde" class="numerico" /></span><span class="celda-intervalo"><input v-model="form.sesionHasta" class="numerico" /></span></div>
            <div class="rango-row"><span class="rango-label">Fecha</span><span class="celda-intervalo"><input v-model="form.fechaDesde" type="date" /></span><span class="celda-intervalo"><input v-model="form.fechaHasta" type="date" /></span></div>
            <div class="rango-row"><span class="rango-label">Agente</span><span class="celda-intervalo"><input v-model="form.agenteDesde" /></span><span class="celda-intervalo"><input v-model="form.agenteHasta" /></span></div>
            <div class="rango-row"><span class="rango-label">Representante</span><span class="celda-intervalo"><input v-model="form.representanteDesde" /></span><span class="celda-intervalo"><input v-model="form.representanteHasta" /></span></div>
            <div class="rango-row"><span class="rango-label">Cliente</span><span class="celda-intervalo"><input v-model="form.clienteDesde" /></span><span class="celda-intervalo"><input v-model="form.clienteHasta" /></span></div>
            <div class="rango-row"><span class="rango-label">Ticket</span><span class="celda-intervalo"><input v-model="form.ticketDesde" class="numerico" /></span><span class="celda-intervalo"><input v-model="form.ticketHasta" class="numerico" /></span></div>
            <div class="rango-row"><span class="rango-label">Origen</span><span class="celda-intervalo"><input v-model="form.origenDesde" /></span><span class="celda-intervalo"><input v-model="form.origenHasta" /></span></div>
            <div class="rango-row"><span class="rango-label">Vendedor</span><span class="celda-intervalo"><input v-model="form.vendedorDesde" /></span><span class="celda-intervalo"><input v-model="form.vendedorHasta" /></span></div>
          </div>
        </fieldset>
        <button type="submit" class="btn-buscar-compact" :disabled="generando">{{ generando ? 'Generando…' : 'Generar' }}</button>
        <p v-if="error" class="flash flash-error">{{ error }}</p>
        <p v-else-if="mensaje" class="flash flash-ok">{{ mensaje }}</p>
      </form>

      <div class="informe-wrap">
        <div v-if="!resultado && !generando" class="sin-datos">Seleccione los intervalos y pulse Generar.</div>
        <div v-else-if="resultado && !tieneDatos" class="sin-datos">No hay tickets que mostrar.</div>
        <div v-else-if="previewHtml" class="it-preview" v-html="previewHtml" />
      </div>
    </div>
  </section>
</template>

<style scoped>
@import './listado-informe-layout.css';
@import './abc-form-view.css';

.informe-wrap { overflow: auto; min-height: 0; background: #e2e8f0; padding: 0.75rem; }
.it-preview { width: 210mm; margin: 0 auto; background: #fff; box-shadow: 0 6px 18px rgb(15 23 42 / 25%); }
.flash { margin: 0.2rem 0 0; padding: 0.35rem; font-size: 0.72rem; }
.sin-datos { padding: 1rem; color: #666; font-size: 0.85rem; }
</style>
