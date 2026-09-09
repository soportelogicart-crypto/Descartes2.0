<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { VentaDetalle } from '@/types/ventas'
import DecimalInput from '@/components/common/DecimalInput.vue'

type DireccionEnvioOpcion = {
  key: string
  departamento: string
  direccion: string
  codigoPostal: string
  poblacion: string
  provincia: string
  pais: string
  email: string
  portes: string
  etiqueta: string
}

const props = defineProps<{
  modelValue: VentaDetalle
  readonly?: boolean
  esNuevo?: boolean
  pasoAlta?: 'tienda' | 'cliente' | 'listo'
  compacto?: boolean
  vendedorNombre?: string
  totales: { bruto: number; descuento: number; iva: number; importe: number }
}>()

const emit = defineEmits<{
  'update:modelValue': [value: VentaDetalle]
  'buscar-vendedor': []
  'vendedor-keydown': [event: KeyboardEvent]
  'vendedor-blur': []
  'buscar-cliente': []
  'cliente-keydown': [event: KeyboardEvent]
}>()

const tiendas = ref<{ value: string; label: string }[]>([])
const tiendaSelect = ref<HTMLSelectElement | null>(null)
const clienteInput = ref<HTMLInputElement | null>(null)
const direccionesEnvio = ref<DireccionEnvioOpcion[]>([])
const dirMenuOpen = ref(false)
const cargandoDirs = ref(false)

const ficha = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const fechaBloqueada = computed(() => Boolean(props.readonly || props.esNuevo))
const puedeElegirDireccion = computed(
  () => !props.readonly && !cargandoDirs.value && direccionesEnvio.value.length > 0
)
const mostrarCliente = computed(() => !props.esNuevo || props.pasoAlta !== 'tienda')
const mostrarDetalles = computed(() => !props.esNuevo || props.pasoAlta === 'listo')

function patch<K extends keyof VentaDetalle>(key: K, value: VentaDetalle[K]) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

function patchMany(partial: Partial<VentaDetalle>) {
  emit('update:modelValue', { ...props.modelValue, ...partial })
}

function fechaInput(): string {
  const iso = props.modelValue.fecha
  if (!iso) return ''
  return iso.slice(0, 10)
}

function setFecha(v: string) {
  if (fechaBloqueada.value) return
  patch('fecha', v ? `${v}T12:00:00` : null)
}

function fpagoCodigo(index: number): string {
  return props.modelValue.formasPago?.[index]?.codigo ?? ''
}

function fpagoImporte(index: number): number {
  return Number(props.modelValue.formasPago?.[index]?.importe ?? 0)
}

function ensureFormas(): { codigo: string; importe: number }[] {
  const actual = [...(props.modelValue.formasPago ?? [])]
  while (actual.length < 2) {
    actual.push({ codigo: '', importe: 0 })
  }
  return actual.slice(0, 2)
}

function setFpago(index: number, codigo: string) {
  const formas = ensureFormas()
  formas[index] = { ...formas[index], codigo }
  patch('formasPago', formas)
}

function setImpFpago(index: number, importe: number) {
  const formas = ensureFormas()
  formas[index] = { ...formas[index], importe }
  patch('formasPago', formas)
}

async function focusTienda() {
  await nextTick()
  tiendaSelect.value?.focus()
}

async function focusCliente() {
  await nextTick()
  clienteInput.value?.focus()
  clienteInput.value?.select()
}

defineExpose({ focusTienda, focusCliente })

async function cargarDireccionesEnvio(clienteCodigo: string) {
  const codigo = clienteCodigo.trim()
  dirMenuOpen.value = false
  if (!codigo || codigo === 'ZZZZZZZZZ') {
    direccionesEnvio.value = []
    return
  }
  cargandoDirs.value = true
  try {
    const { data } = await api.get(
      `/api/mantenimiento/clientes/${encodeURIComponent(codigo)}/direcciones`
    )
    const items = Array.isArray(data?.items) ? data.items : Array.isArray(data) ? data : []
    direccionesEnvio.value = items
      .filter((d: { tipo?: string }) => String(d.tipo ?? '').toUpperCase() === 'E')
      .map((d: Record<string, unknown>, idx: number) => {
        const departamento = String(d.departamento ?? '').trim()
        const direccion = String(d.direccion ?? '').trim()
        const codigoPostal = String(d.codigoPostal ?? '').trim()
        const poblacion = String(d.poblacion ?? '').trim()
        const provincia = String(d.provincia ?? '').trim()
        const pais = String(d.pais ?? '').trim()
        const email = String(d.email ?? '').trim()
        const portes = String(d.portes ?? '').trim()
        const etiqueta = [departamento || null, direccion, codigoPostal, poblacion]
          .filter(Boolean)
          .join(' · ')
        return {
          key: `${d.tipo}-${d.nroLin ?? idx}`,
          departamento,
          direccion,
          codigoPostal,
          poblacion,
          provincia,
          pais,
          email,
          portes,
          etiqueta: etiqueta || `Direccion ${idx + 1}`,
        } satisfies DireccionEnvioOpcion
      })
  } catch {
    direccionesEnvio.value = []
  } finally {
    cargandoDirs.value = false
  }
}

function aplicarDireccion(d: DireccionEnvioOpcion) {
  patchMany({
    ...(d.departamento ? { razonSocial2: d.departamento } : {}),
    direccionEnvio: d.direccion,
    codigoPostalEnvio: d.codigoPostal,
    poblacionEnvio: d.poblacion,
    provinciaEnvio: d.provincia,
    paisEnvio: d.pais,
    ...(d.email ? { email: d.email } : {}),
    ...(d.portes ? { portes: d.portes } : {}),
  })
  dirMenuOpen.value = false
}

function toggleDirMenu() {
  if (!puedeElegirDireccion.value) return
  dirMenuOpen.value = !dirMenuOpen.value
}

function onDocClick(e: MouseEvent) {
  const t = e.target
  if (!(t instanceof Element)) return
  if (!t.closest('.dir-envio')) dirMenuOpen.value = false
}

watch(
  () => props.modelValue.cliente,
  (cli) => {
    void cargarDireccionesEnvio(String(cli ?? ''))
  },
  { immediate: true }
)

onMounted(async () => {
  document.addEventListener('click', onDocClick)
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', {
      params: { activo: true, pageSize: 200 },
    })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))
  } catch {
    tiendas.value = []
  }
})

onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
})
</script>

<template>
  <div class="tab-form">
    <div class="doc-row">
      <section class="section grow">
        <h3>Documento</h3>
        <div class="fields cols-6">
          <label class="field field-tienda">
            <span class="label">Tienda</span>
            <select
              ref="tiendaSelect"
              :value="ficha.empresa"
              :disabled="readonly || !esNuevo"
              @change="patch('empresa', ($event.target as HTMLSelectElement).value)"
            >
              <option value="">--</option>
              <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </label>
          <label class="field">
            <span class="label">N. albaran</span>
            <DecimalInput :model-value="(ficha.albaran as number | null) ?? null" :integer="true" readonly />
          </label>
          <label class="field">
            <span class="label">Fecha</span>
            <input
              :value="fechaInput()"
              type="date"
              :readonly="fechaBloqueada"
              :title="esNuevo ? 'La fecha de una venta nueva es siempre la de hoy' : undefined"
              @input="setFecha(($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Pedido</span>
            <DecimalInput
              :model-value="(ficha.pedido as number | null) ?? null"
              :empty-as-null="true"
              :integer="true"
              :readonly="readonly"
              @update:model-value="patch('pedido', $event)"
            />
          </label>
          <label class="field">
            <span class="label">Puesto</span>
            <input
              :value="ficha.puesto ?? ''"
              maxlength="2"
              :readonly="readonly"
              @input="patch('puesto', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field checkbox">
            <input
              type="checkbox"
              :checked="!!ficha.sujetoPasivo"
              :disabled="readonly"
              @change="patch('sujetoPasivo', ($event.target as HTMLInputElement).checked)"
            />
            <span>Sujeto pasivo</span>
          </label>
        </div>
      </section>
      <div class="totales-box">
        <div><span>Bruto</span><strong>{{ totales.bruto.toFixed(2) }}</strong></div>
        <div><span>Dto</span><strong>{{ totales.descuento.toFixed(2) }}</strong></div>
        <div><span>IVA</span><strong>{{ totales.iva.toFixed(2) }}</strong></div>
        <div class="imp"><span>Importe</span><strong>{{ totales.importe.toFixed(2) }}</strong></div>
      </div>
    </div>

    <div v-if="mostrarCliente" class="section-row paired single">
      <section class="section">
        <h3>Cliente</h3>
        <div class="fields cols-3">
          <label class="field">
            <span class="label">Codigo</span>
            <div class="vendedor-row">
              <input
                ref="clienteInput"
                :value="ficha.cliente ?? ''"
                maxlength="9"
                :readonly="readonly"
                placeholder="Vacío + Intro = venta rápida"
                title="Intro vacío = venta rápida (ZZZZZZZZZ); F4 o … para buscar cliente"
                @input="patch('cliente', ($event.target as HTMLInputElement).value)"
                @keydown="emit('cliente-keydown', $event)"
              />
              <button
                type="button"
                class="btn-buscar"
                :disabled="readonly"
                title="Buscar cliente (Intro / F4)"
                @click="emit('buscar-cliente')"
              >
                ...
              </button>
            </div>
          </label>
          <label class="field">
            <span class="label">NIF</span>
            <input
              :value="ficha.nif ?? ''"
              maxlength="16"
              :readonly="readonly"
              @input="patch('nif', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Vendedor</span>
            <div class="vendedor-row">
              <input
                :value="ficha.vendedor ?? ''"
                maxlength="4"
                placeholder="Código"
                :readonly="readonly"
                :title="vendedorNombre || 'Vendedor (trabajador). Por defecto el del puesto. Intro / F4 para buscar.'"
                @input="patch('vendedor', ($event.target as HTMLInputElement).value)"
                @keydown="emit('vendedor-keydown', $event)"
                @blur="emit('vendedor-blur')"
              />
              <button
                type="button"
                class="btn-buscar"
                :disabled="readonly"
                :title="vendedorNombre ? `Buscar vendedor — ${vendedorNombre}` : 'Buscar vendedor (Intro / F4)'"
                @click="emit('buscar-vendedor')"
              >
                ...
              </button>
            </div>
            <small v-if="vendedorNombre" class="field-help">{{ vendedorNombre }}</small>
          </label>
          <label class="field span-2">
            <span class="label">Razon social</span>
            <input
              :value="ficha.razonSocial ?? ''"
              maxlength="50"
              :readonly="readonly"
              @input="patch('razonSocial', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Razon social 2</span>
            <input
              :value="ficha.razonSocial2 ?? ''"
              maxlength="60"
              :readonly="readonly"
              @input="patch('razonSocial2', ($event.target as HTMLInputElement).value)"
            />
          </label>
        </div>
      </section>
    </div>

    <details v-if="mostrarDetalles" class="detalles-adicionales" :open="!compacto">
      <summary>Más datos de la venta</summary>
      <section class="section">
        <h3>Envio / contacto</h3>
        <div class="fields cols-3">
          <div class="field span-2 dir-envio">
            <span class="label">Direccion</span>
            <div class="dir-row">
              <input
                :value="ficha.direccionEnvio ?? ''"
                maxlength="50"
                :readonly="readonly"
                @input="patch('direccionEnvio', ($event.target as HTMLInputElement).value)"
              />
              <button
                type="button"
                class="btn-dirs"
                :disabled="!puedeElegirDireccion"
                :title="
                  puedeElegirDireccion
                    ? 'Elegir otra direccion de envio del cliente'
                    : 'Este cliente no tiene direcciones en ClientesDirecciones'
                "
                @click.stop="toggleDirMenu"
              >
                ▾
              </button>
            </div>
            <ul v-if="dirMenuOpen && puedeElegirDireccion" class="dir-menu" role="listbox">
              <li
                v-for="d in direccionesEnvio"
                :key="d.key"
                role="option"
                @click="aplicarDireccion(d)"
              >
                <strong v-if="d.departamento">{{ d.departamento }}</strong>
                <span>{{ d.direccion || '—' }}</span>
                <small>{{ [d.codigoPostal, d.poblacion, d.provincia].filter(Boolean).join(' · ') }}</small>
              </li>
            </ul>
          </div>
          <label class="field">
            <span class="label">C.P.</span>
            <input
              :value="ficha.codigoPostalEnvio ?? ''"
              maxlength="8"
              :readonly="readonly"
              @input="patch('codigoPostalEnvio', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Poblacion</span>
            <input
              :value="ficha.poblacionEnvio ?? ''"
              maxlength="50"
              :readonly="readonly"
              @input="patch('poblacionEnvio', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Provincia</span>
            <input
              :value="ficha.provinciaEnvio ?? ''"
              maxlength="50"
              :readonly="readonly"
              @input="patch('provinciaEnvio', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Pais</span>
            <input
              :value="ficha.paisEnvio ?? ''"
              maxlength="50"
              :readonly="readonly"
              @input="patch('paisEnvio', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Telefono</span>
            <input
              :value="ficha.telefono ?? ''"
              maxlength="15"
              :readonly="readonly"
              @input="patch('telefono', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Movil</span>
            <input
              :value="ficha.telefono2 ?? ''"
              maxlength="15"
              :readonly="readonly"
              @input="patch('telefono2', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field">
            <span class="label">Fax</span>
            <input
              :value="ficha.fax ?? ''"
              maxlength="15"
              :readonly="readonly"
              @input="patch('fax', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="field span-2">
            <span class="label">Email</span>
            <input
              :value="ficha.email ?? ''"
              maxlength="200"
              type="email"
              :readonly="readonly"
              @input="patch('email', ($event.target as HTMLInputElement).value)"
            />
          </label>
        </div>
      </section>

    <section class="section">
      <h3>Otros</h3>
      <div class="fields cols-6">
        <label class="field">
          <span class="label">Transportista</span>
          <input
            :value="ficha.transporte ?? ''"
            maxlength="40"
            :readonly="readonly"
            @input="patch('transporte', ($event.target as HTMLInputElement).value)"
          />
        </label>
        <label class="field">
          <span class="label">Referencia 1</span>
          <input
            :value="ficha.referencia1 ?? ''"
            maxlength="40"
            :readonly="readonly"
            @input="patch('referencia1', ($event.target as HTMLInputElement).value)"
          />
        </label>
        <label class="field">
          <span class="label">Referencia 2</span>
          <input
            :value="ficha.referencia2 ?? ''"
            maxlength="40"
            :readonly="readonly"
            @input="patch('referencia2', ($event.target as HTMLInputElement).value)"
          />
        </label>
        <label class="field">
          <span class="label">N. serie</span>
          <input
            :value="ficha.numeroDeSerie ?? ''"
            maxlength="20"
            :readonly="readonly"
            @input="patch('numeroDeSerie', ($event.target as HTMLInputElement).value)"
          />
        </label>
        <label class="field">
          <span class="label">Almacen</span>
          <DecimalInput
            :model-value="(ficha.almacen as number | null) ?? null"
            :empty-as-null="true"
            :integer="true"
            :readonly="readonly"
            @update:model-value="patch('almacen', $event)"
          />
        </label>
        <label class="field">
          <span class="label">Representante</span>
          <input
            :value="ficha.representante ?? ''"
            maxlength="4"
            :readonly="readonly"
            @input="patch('representante', ($event.target as HTMLInputElement).value)"
          />
        </label>
      </div>
    </section>

    <section class="section">
      <h3>Formas de pago</h3>
      <div class="fields cols-6">
        <label class="field">
          <span class="label">Fpago 1</span>
          <input
            :value="fpagoCodigo(0)"
            maxlength="2"
            :readonly="readonly"
            @input="setFpago(0, ($event.target as HTMLInputElement).value)"
          />
        </label>
        <label class="field">
          <span class="label">Importe 1</span>
          <DecimalInput
            :model-value="fpagoImporte(0)"
            :empty-as-null="false"
            :readonly="readonly"
            @update:model-value="setImpFpago(0, $event ?? 0)"
          />
        </label>
        <label class="field">
          <span class="label">Fpago 2</span>
          <input
            :value="fpagoCodigo(1)"
            maxlength="2"
            :readonly="readonly"
            @input="setFpago(1, ($event.target as HTMLInputElement).value)"
          />
        </label>
        <label class="field">
          <span class="label">Importe 2</span>
          <DecimalInput
            :model-value="fpagoImporte(1)"
            :empty-as-null="false"
            :readonly="readonly"
            @update:model-value="setImpFpago(1, $event ?? 0)"
          />
        </label>
      </div>
    </section>
    </details>
  </div>
</template>

<style scoped>
.tab-form {
  background: #f8fafc;
  border: 1px solid #c5cdd8;
  padding: 0.4rem;
  max-width: 960px;
  border-radius: 0 0 6px 6px;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.doc-row {
  display: flex;
  gap: 0.35rem;
  align-items: stretch;
}

.doc-row .grow {
  flex: 1;
  min-width: 0;
}

.section-row.paired {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
  align-items: stretch;
}

.section-row.paired.single {
  grid-template-columns: 1fr;
}

.detalles-adicionales {
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #f8fafc;
  padding: 0.25rem;
}

.detalles-adicionales > summary {
  cursor: pointer;
  color: #1e40af;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 0.2rem 0.35rem;
}

.detalles-adicionales[open] {
  display: grid;
  gap: 0.35rem;
}

.section {
  margin: 0;
  padding: 0.3rem 0.4rem 0.35rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}

.section h3 {
  margin: 0 0 0.25rem;
  font-size: 0.68rem;
  font-weight: 700;
  color: #334155;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 0.15rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.fields {
  display: grid;
  gap: 0.2rem 0.35rem;
}

.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.cols-6 {
  grid-template-columns: repeat(6, minmax(0, 1fr));
}

.field {
  display: grid;
  gap: 0.05rem;
  font-size: 0.68rem;
  min-width: 0;
}

.field.checkbox {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  padding-top: 0.85rem;
}

.span-2 {
  grid-column: span 2;
}

.label {
  color: #64748b;
  white-space: nowrap;
}

.field-help {
  color: #475569;
  font-size: 0.66rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

input,
select {
  width: 100%;
  min-width: 0;
  padding: 0.12rem 0.25rem;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  font-size: 0.75rem;
  line-height: 1.2;
  background: #fff;
  color: #0f172a;
}

input:read-only,
select:disabled {
  background: #f1f5f9;
}

.field-tienda select:not(:disabled) {
  border-color: #2563eb;
  background: #eff6ff;
  font-weight: 600;
}

.dir-envio {
  position: relative;
}

.dir-row {
  display: flex;
  gap: 0.2rem;
  align-items: stretch;
}

.dir-row input {
  flex: 1;
}

.vendedor-row {
  display: flex;
  gap: 0.2rem;
  align-items: stretch;
}

.vendedor-row input {
  flex: 1;
  min-width: 0;
}

.btn-buscar {
  flex: 0 0 1.7rem;
  border: 1px solid #64748b;
  border-radius: 2px;
  background: #fff;
  cursor: pointer;
  font-size: 0.7rem;
  padding: 0;
  color: #334155;
}

.btn-buscar:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-buscar:not(:disabled):hover {
  background: #eff6ff;
  border-color: #2563eb;
  color: #1d4ed8;
}

.btn-dirs {
  flex: 0 0 1.7rem;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  background: #fff;
  color: #334155;
  font-size: 0.85rem;
  line-height: 1;
  cursor: pointer;
  padding: 0;
}

.btn-dirs:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  background: #f1f5f9;
}

.btn-dirs:not(:disabled):hover {
  background: #eff6ff;
  border-color: #2563eb;
}

.dir-menu {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  top: calc(100% + 2px);
  margin: 0;
  padding: 0.2rem 0;
  list-style: none;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
  max-height: 12rem;
  overflow: auto;
}

.dir-menu li {
  display: grid;
  gap: 0.05rem;
  padding: 0.35rem 0.5rem;
  cursor: pointer;
  font-size: 0.72rem;
  color: #0f172a;
}

.dir-menu li:hover {
  background: #eff6ff;
}

.dir-menu strong {
  font-size: 0.7rem;
  color: #1e40af;
}

.dir-menu small {
  color: #64748b;
}

.totales-box {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.1rem 0.45rem;
  align-content: center;
  min-width: 9.5rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.3rem 0.45rem;
  font-size: 0.68rem;
}

.totales-box span {
  color: #64748b;
}

.totales-box strong {
  font-variant-numeric: tabular-nums;
  display: block;
  text-align: right;
}

.totales-box .imp strong {
  font-size: 0.9rem;
  color: #0f172a;
}

@media (max-width: 900px) {
  .doc-row {
    flex-direction: column;
  }
  .section-row.paired {
    grid-template-columns: 1fr;
  }
  .cols-6 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
