<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { actualizarVenta, finalizarVenta, listarVentas, obtenerVenta } from '@/api/ventas'
import TpvClienteModal from '@/components/tpv/TpvClienteModal.vue'
import TpvTecladoNumerico from '@/components/tpv/TpvTecladoNumerico.vue'
import DatosFacturaTicketForm, {
  type DatosFacturaTicket,
} from '@/components/ventas/DatosFacturaTicketForm.vue'
import { extractApiError } from '@/composables/extractApiError'
import type { TpvCliente } from '@/types/tpv'
import type { VentaDetalle, VentaPayload, VentaResumen } from '@/types/ventas'

const props = defineProps<{
  open: boolean
  empresa: string
}>()

const emit = defineEmits<{
  cancelar: []
  convertido: [payload: { factura: VentaDetalle; ticketNegativo: VentaDetalle | null }]
}>()

const numero = ref('')
const inputNumero = ref<HTMLInputElement | null>(null)
const resultados = ref<VentaResumen[]>([])
const ticket = ref<VentaDetalle | null>(null)
const datos = ref<DatosFacturaTicket>(datosVacios())
const buscarClienteAbierto = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)

function datosVacios(): DatosFacturaTicket {
  return {
    cliente: '',
    razonSocial: '',
    nif: '',
    direccion: '',
    codigoPostal: '',
    poblacion: '',
    provincia: '',
  }
}

const datosFiscalesOk = computed(() => {
  const documento = datos.value.nif.toUpperCase().replace(/[\s.\-]/g, '')
  return (
    datos.value.razonSocial.trim() !== '' &&
    datos.value.cliente.trim() !== '' &&
    documento.length >= 7 &&
    !/^[0X]+$/.test(documento)
  )
})

const yaConvertido = computed(() => (Number(ticket.value?.facturaConversion) || 0) > 0)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    numero.value = ''
    resultados.value = []
    ticket.value = null
    datos.value = datosVacios()
    buscarClienteAbierto.value = false
    error.value = null
    await foco()
  }
)

async function foco() {
  await nextTick()
  requestAnimationFrame(() => {
    inputNumero.value?.focus()
    inputNumero.value?.select()
  })
}

function pulsar(tecla: string) {
  if (numero.value.length >= 12) return
  numero.value = (numero.value + tecla).replace(/^0+(?=\d)/, '')
  void foco()
}

function borrarDigito() {
  numero.value = numero.value.slice(0, -1)
  void foco()
}

function limpiarNumero() {
  numero.value = ''
  void foco()
}

function texto(v: unknown): string {
  return String(v ?? '').trim()
}

function rellenarDesdeTicket(detalle: VentaDetalle) {
  const cliente = texto(detalle.cliente)
  datos.value = {
    // El cliente de contado no sirve para facturar: se deja vacío para elegir uno.
    cliente: cliente.toUpperCase() === 'ZZZZZZZZZ' ? '' : cliente,
    razonSocial: texto(detalle.razonSocial),
    nif: texto(detalle.nif),
    direccion: texto(detalle.direccionEnvio),
    codigoPostal: texto(detalle.codigoPostalEnvio),
    poblacion: texto(detalle.poblacionEnvio),
    provincia: texto(detalle.provinciaEnvio),
  }
}

async function buscar() {
  const documento = Number(numero.value.trim())
  if (!Number.isInteger(documento) || documento <= 0) {
    error.value = 'Introduzca el número del ticket ya cobrado'
    return
  }
  loading.value = true
  error.value = null
  ticket.value = null
  resultados.value = []
  try {
    const data = await listarVentas({
      empresa: props.empresa,
      documento,
      page: 1,
      pageSize: 50,
    })
    const encontrados = data.items.filter((v) => {
      const ft = texto(v.facturaTipo).toUpperCase()
      return ft === 'T' && Number(v.factura) === documento && Number(v.importe) > 0
    })
    resultados.value = encontrados
    if (!encontrados.length) {
      error.value = `No se ha encontrado un ticket cobrado con el número ${documento}`
    } else if (encontrados.length === 1) {
      await seleccionar(encontrados[0])
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo buscar el ticket')
  } finally {
    loading.value = false
  }
}

async function seleccionar(resumen: VentaResumen) {
  loading.value = true
  error.value = null
  try {
    const detalle = await obtenerVenta(resumen.empresa, resumen.tipo, resumen.albaran)
    if (texto(detalle.facturaTipo).toUpperCase() !== 'T' || Number(detalle.factura) <= 0) {
      error.value = 'Ese documento no es un ticket cobrado'
      return
    }
    if (Number(detalle.importe) <= 0) {
      error.value = 'Solo se puede pasar a factura un ticket de venta'
      return
    }
    ticket.value = detalle
    resultados.value = []
    rellenarDesdeTicket(detalle)
    if (yaConvertido.value) {
      error.value = `Este ticket ya se pasó a la factura ${detalle.facturaConversion}`
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el ticket')
  } finally {
    loading.value = false
  }
}

function otroTicket() {
  ticket.value = null
  datos.value = datosVacios()
  error.value = null
  void foco()
}

function onClienteElegido(cli: TpvCliente) {
  buscarClienteAbierto.value = false
  datos.value = {
    cliente: texto(cli.codigo),
    razonSocial: texto(cli.razonSocial),
    nif: texto(cli.nif),
    direccion: texto(cli.direccion),
    codigoPostal: texto(cli.codigoPostal),
    poblacion: texto(cli.poblacion),
    provincia: texto(cli.provincia),
  }
}

function payloadFiscal(venta: VentaDetalle): VentaPayload {
  const d = datos.value
  return {
    empresa: venta.empresa,
    cliente: d.cliente.trim(),
    razonSocial: d.razonSocial.trim(),
    nif: d.nif.trim(),
    direccionEnvio: d.direccion.trim(),
    codigoPostalEnvio: d.codigoPostal.trim(),
    poblacionEnvio: d.poblacion.trim(),
    provinciaEnvio: d.provincia.trim(),
    lineas: venta.lineas ?? [],
  }
}

function cambiaronDatos(venta: VentaDetalle): boolean {
  const d = datos.value
  return (
    texto(venta.cliente) !== d.cliente.trim() ||
    texto(venta.razonSocial) !== d.razonSocial.trim() ||
    texto(venta.nif) !== d.nif.trim() ||
    texto(venta.direccionEnvio) !== d.direccion.trim() ||
    texto(venta.codigoPostalEnvio) !== d.codigoPostal.trim() ||
    texto(venta.poblacionEnvio) !== d.poblacion.trim() ||
    texto(venta.provinciaEnvio) !== d.provincia.trim()
  )
}

async function convertir() {
  const venta = ticket.value
  if (!venta || loading.value || yaConvertido.value) return
  if (!datosFiscalesOk.value) {
    error.value = 'Para la factura hacen falta cliente, NIF válido y razón social'
    return
  }
  loading.value = true
  error.value = null
  try {
    let actual = venta
    if (cambiaronDatos(venta)) {
      actual = await actualizarVenta(venta.empresa, venta.tipo, venta.albaran, payloadFiscal(venta))
      ticket.value = actual
    }
    const factura = await finalizarVenta(actual.empresa, actual.tipo, actual.albaran, 'F')
    let ticketNegativo: VentaDetalle | null = null
    const albaranNegativo = Number(factura.albaranTicketNegativo) || 0
    if (albaranNegativo > 0) {
      try {
        ticketNegativo = await obtenerVenta(factura.empresa, factura.tipo, albaranNegativo)
      } catch {
        ticketNegativo = null
      }
    }
    emit('convertido', { factura, ticketNegativo })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo pasar el ticket a factura')
  } finally {
    loading.value = false
  }
}

function euros(v: unknown): string {
  return `${Number(v ?? 0).toFixed(2).replace('.', ',')} €`
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="dialog" aria-modal="true" @mousedown.self.prevent>
      <section class="ventana">
        <header class="toolbar">
          <span class="titulo">Ticket a factura</span>
        </header>

        <div class="cuerpo">
          <section v-if="!ticket" class="section">
            <h3>Ticket ya cobrado</h3>
            <form class="busqueda" @submit.prevent="buscar">
              <label for="tpv-ticket-factura-numero">Nº de ticket</label>
              <input
                id="tpv-ticket-factura-numero"
                ref="inputNumero"
                v-model="numero"
                type="text"
                inputmode="numeric"
                autocomplete="off"
              />
              <button type="submit" class="tool-btn primary" :disabled="loading">Buscar</button>
            </form>
            <TpvTecladoNumerico
              class="pad-documento"
              @tecla="pulsar"
              @borrar="borrarDigito"
              @limpiar="limpiarNumero"
            />
          </section>

          <div v-if="resultados.length > 1" class="section resultados">
            <h3>Varios tickets con ese número</h3>
            <button
              v-for="r in resultados"
              :key="`${r.empresa}-${r.tipo}-${r.albaran}`"
              type="button"
              class="fila"
              @click="seleccionar(r)"
            >
              <strong>Ticket {{ r.factura }}</strong>
              <span>{{ r.fecha?.slice(0, 10) }} · {{ r.razonSocial || r.cliente || 'Sin cliente' }}</span>
              <span class="num">{{ euros(r.importe) }}</span>
            </button>
          </div>

          <template v-if="ticket">
            <section class="section">
              <h3>Ticket {{ ticket.factura }}</h3>
              <dl class="resumen">
                <div>
                  <dt>Fecha</dt>
                  <dd>{{ ticket.fecha?.slice(0, 10) || '—' }}</dd>
                </div>
                <div>
                  <dt>Importe</dt>
                  <dd>{{ euros(ticket.importe) }}</dd>
                </div>
                <div>
                  <dt>Forma de pago</dt>
                  <dd>{{ ticket.formasPago?.[0]?.codigo || '—' }}</dd>
                </div>
              </dl>
              <p class="nota">
                El ticket se conserva. Se crea un ticket negativo que lo compensa y una factura de
                contado con la misma forma de pago. No se vuelve a cobrar.
              </p>
            </section>

            <DatosFacturaTicketForm
              v-model="datos"
              tactil
              :disabled="loading || yaConvertido"
              @buscar-cliente="buscarClienteAbierto = true"
            />
          </template>

          <p v-if="error" class="error">{{ error }}</p>
          <p v-else-if="loading" class="estado">Consultando…</p>
        </div>

        <footer class="pie">
          <button type="button" class="tool-btn" :disabled="loading" @click="emit('cancelar')">
            Cancelar
          </button>
          <button v-if="ticket" type="button" class="tool-btn" :disabled="loading" @click="otroTicket">
            Otro ticket
          </button>
          <button
            type="button"
            class="tool-btn primary"
            :disabled="loading || !ticket || !datosFiscalesOk || yaConvertido"
            @click="convertir"
          >
            Pasar a factura
          </button>
        </footer>
      </section>
    </div>

    <TpvClienteModal
      :open="buscarClienteAbierto"
      @seleccionar="onClienteElegido"
      @quitar="buscarClienteAbierto = false"
      @cancelar="buscarClienteAbierto = false"
    />
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 55;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}

.ventana {
  display: flex;
  flex-direction: column;
  width: min(44rem, 96vw);
  max-height: 92vh;
  background: #f8fafc;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  overflow: hidden;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
  color: #0f172a;
}

.toolbar {
  display: flex;
  align-items: center;
  min-height: 2.4rem;
  padding: 0.3rem 0.65rem;
  background: linear-gradient(180deg, #f8fafc, #e5e7eb);
  border-bottom: 1px solid #94a3b8;
}

.titulo {
  font-size: 0.9rem;
  font-weight: 700;
  color: #1e293b;
}

.cuerpo {
  display: grid;
  gap: 0.55rem;
  min-height: 0;
  padding: 0.65rem;
  overflow: auto;
}

.section {
  padding: 0.45rem 0.55rem 0.55rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.section h3 {
  margin: 0 0 0.4rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid #e2e8f0;
  color: #334155;
  font-size: 0.78rem;
  font-weight: 700;
}

.busqueda {
  display: grid;
  grid-template-columns: auto minmax(8rem, 14rem) auto;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
}

.busqueda input {
  min-height: 2.3rem;
  padding: 0.3rem 0.5rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
  font-size: 1rem;
}

.busqueda input:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18);
}

.pad-documento {
  width: min(19rem, 100%);
  margin-top: 0.55rem;
}

.resultados {
  display: grid;
  gap: 4px;
}

.fila {
  display: grid;
  grid-template-columns: 8rem 1fr 6rem;
  gap: 0.5rem;
  min-height: 2.4rem;
  padding: 0.3rem 0.5rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 3px;
  font: inherit;
  font-size: 0.85rem;
  text-align: left;
  cursor: pointer;
}

.fila:hover {
  background: #eff6ff;
  border-color: #93c5fd;
}

.num {
  text-align: right;
}

.resumen {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.35rem 0.55rem;
  margin: 0;
}

.resumen dt {
  color: #64748b;
  font-size: 0.72rem;
}

.resumen dd {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
}

.nota {
  margin: 0.45rem 0 0;
  color: #475569;
  font-size: 0.78rem;
}

.error {
  margin: 0;
  padding: 0.4rem 0.55rem;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 3px;
  color: #b91c1c;
  font-size: 0.82rem;
}

.estado {
  margin: 0;
  color: #475569;
  font-size: 0.82rem;
}

.pie {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  padding: 0.45rem 0.65rem;
  background: linear-gradient(180deg, #f8fafc, #e5e7eb);
  border-top: 1px solid #94a3b8;
}

.tool-btn {
  min-height: 2.5rem;
  padding: 0.3rem 0.9rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  color: #1e293b;
  font: inherit;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
}

.tool-btn:hover:not(:disabled) {
  background: #eff6ff;
  border-color: #60a5fa;
}

.tool-btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.tool-btn.primary:hover:not(:disabled) {
  background: #1d4ed8;
}

.tool-btn:disabled {
  opacity: 0.5;
  cursor: default;
}
</style>
