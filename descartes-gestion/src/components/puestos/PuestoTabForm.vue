<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { PuestoField, PuestoImpresoraDoc, PuestoSection } from '@/config/puestos-tabs'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { entidadDesdeOptionsSource } from '@/config/entidad-lookup'
import {
  listarImpresorasSistema,
  type ImpresoraSistema,
} from '@/composables/useImpresorasSistema'

const props = defineProps<{
  sections: PuestoSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
  ocultarCabecera?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const trabajadoresOptions = ref<{ value: string; label: string }[]>([])
const usuariosOptions = ref<{ value: string; label: string }[]>([])
const tiendasOptions = ref<{ value: string; label: string }[]>([])

const impresoras = ref<ImpresoraSistema[]>([])
const impresorasLoading = ref(false)
const impresorasError = ref<string | null>(null)
const impresorasOnline = ref(false)
const docSeleccionado = ref<string | null>(null)

type PlantillaOpcion = {
  id: number
  tipo: string
  nombre: string
  activa: boolean
  empresaCodigo: string
}
const plantillas = ref<PlantillaOpcion[]>([])
const plantillasLoading = ref(false)
const plantillasError = ref<string | null>(null)

const tieneSeccionImpresoras = computed(() => props.sections.some((s) => s.kind === 'impresoras'))

const empresaPlantillas = computed(() => String(props.modelValue.tiendaCodigo ?? '').trim().toUpperCase())

const tieneFormato = computed(() =>
  (props.sections.find((s) => s.kind === 'impresoras')?.impresoras ?? []).some((r) => r.formatoKey)
)

/** Ignora valores legacy tipo AlbaranStd.rpt. */
function esFormatoLegacyRpt(valor: unknown): boolean {
  return /\.rpt$/i.test(String(valor ?? '').trim())
}

function formatoActual(row: PuestoImpresoraDoc): string {
  if (!row.formatoKey) return ''
  const v = String(props.modelValue[row.formatoKey] ?? '').trim()
  if (!v || esFormatoLegacyRpt(v)) return ''
  return v
}

const seccionesVisibles = computed(() => {
  if (!props.ocultarCabecera) return props.sections
  return props.sections
    .map((section) => {
      if (section.kind === 'impresoras') return section
      return {
        ...section,
        fields: (section.fields ?? []).filter((f) => f.key !== 'codigo' && f.key !== 'descripcion'),
      }
    })
    .filter((section) => section.kind === 'impresoras' || (section.fields?.length ?? 0) > 0)
})

const allFields = computed(() => props.sections.flatMap((s) => s.fields ?? []))

async function cargarPlantillas() {
  if (!tieneSeccionImpresoras.value) return
  plantillasLoading.value = true
  plantillasError.value = null
  try {
    // Sin filtrar por empresa: las plantillas pueden estar en otra tienda que la de arqueo.
    const { data } = await api.get('/api/mantenimiento/documento-plantillas')
    let lista = ((data?.items ?? []) as Record<string, unknown>[]).map((p) => ({
      id: Number(p.id),
      tipo: String(p.tipo ?? ''),
      nombre: String(p.nombre ?? ''),
      activa: Boolean(p.activa),
      empresaCodigo: String(p.empresaCodigo ?? '').trim(),
    }))
    const emp = empresaPlantillas.value
    if (emp) {
      const deEmpresa = lista.filter((p) => p.empresaCodigo.toUpperCase() === emp)
      if (deEmpresa.length > 0) lista = deEmpresa
    }
    plantillas.value = lista
    if (lista.length === 0) {
      plantillasError.value =
        'No hay plantillas en el servidor. Créelas en Configuración → Albaranes / Tickets / Etiquetas.'
    }
  } catch (e: unknown) {
    plantillas.value = []
    plantillasError.value = e instanceof Error ? e.message : 'No se pudieron cargar plantillas'
  } finally {
    plantillasLoading.value = false
  }
}

function plantillasPara(row: PuestoImpresoraDoc): PlantillaOpcion[] {
  if (!row.plantillaTipo) return plantillas.value
  const tipos = Array.isArray(row.plantillaTipo) ? row.plantillaTipo : [row.plantillaTipo]
  const matched = plantillas.value.filter((p) => tipos.includes(p.tipo))
  // Si no hay del tipo (p.ej. creó «Albaran» con otro tipo), mostrar todas para poder elegirla.
  return matched.length > 0 ? matched : plantillas.value
}

function etiquetaPlantilla(p: PlantillaOpcion, row: PuestoImpresoraDoc): string {
  const tipos = row.plantillaTipo
    ? Array.isArray(row.plantillaTipo)
      ? row.plantillaTipo
      : [row.plantillaTipo]
    : []
  const fueraDeTipo = tipos.length > 0 && !tipos.includes(p.tipo)
  const tipoLbl =
    p.tipo === 'albaran'
      ? 'Albarán'
      : p.tipo === 'factura-contado'
        ? 'Fac. contado'
        : p.tipo === 'factura-credito'
          ? 'Fac. crédito'
            : p.tipo === 'factura-rectificativa'
            ? 'Rectificativa'
            : p.tipo === 'ticket'
              ? 'Ticket'
              : p.tipo === 'etiqueta'
                ? 'Etiqueta'
                : p.tipo
  const base = fueraDeTipo ? `${tipoLbl} · ${p.nombre}` : p.nombre
  return p.activa ? `${base} (activa)` : base
}

function onFormatoChange(row: PuestoImpresoraDoc, value: string) {
  if (!row.formatoKey) return
  const max = row.formatoMax ?? 100
  updateField(row.formatoKey, value.slice(0, max))
}

async function cargarImpresoras() {
  impresorasLoading.value = true
  impresorasError.value = null
  try {
    const res = await listarImpresorasSistema()
    impresoras.value = res.printers
    impresorasOnline.value = res.agenteOnline && res.ok
    if (!res.ok || res.printers.length === 0) {
      impresorasError.value =
        res.message ||
        (res.agenteOnline
          ? 'No se detectaron impresoras en este equipo.'
          : 'Agente no disponible. Ejecute Descartes Electron.')
    }
  } catch (e: unknown) {
    impresoras.value = []
    impresorasOnline.value = false
    impresorasError.value = e instanceof Error ? e.message : 'Error al detectar impresoras'
  } finally {
    impresorasLoading.value = false
  }
}

const necesitaImpresorasSistema = computed(
  () =>
    allFields.value.some((f) => f.optionsSource === 'impresoras-sistema') ||
    tieneSeccionImpresoras.value
)

onMounted(async () => {
  const needsTrabajadores = allFields.value.some((f) => f.optionsSource === 'trabajadores')
  const needsUsuarios = allFields.value.some((f) => f.optionsSource === 'usuarios')
  const needsTiendas = allFields.value.some((f) => f.optionsSource === 'tiendas')

  const requests: Promise<void>[] = []

  if (needsTrabajadores) {
    requests.push(
      api.get('/api/mantenimiento/trabajadores', { params: { activo: true, pageSize: 500 } }).then(({ data }) => {
        trabajadoresOptions.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
          value: String(t.codigo).trim(),
          label: `${String(t.codigo).trim()} - ${t.nombre}`,
        }))
      })
    )
  }

  if (needsUsuarios) {
    requests.push(
      api.get('/api/mantenimiento/usuarios', { params: { activo: true, pageSize: 500 } }).then(({ data }) => {
        usuariosOptions.value = (data.items ?? []).map((u: { codigo: string; nombre: string }) => ({
          value: String(u.codigo).trim(),
          label: `${String(u.codigo).trim()} - ${u.nombre}`,
        }))
      })
    )
  }

  if (needsTiendas) {
    requests.push(
      api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } }).then(({ data }) => {
        tiendasOptions.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
          value: String(t.codigo).trim(),
          label: `${String(t.codigo).trim()} - ${t.nombre}`,
        }))
      })
    )
  }

  await Promise.all([
    ...requests,
    necesitaImpresorasSistema.value ? cargarImpresoras() : Promise.resolve(),
    cargarPlantillas(),
  ])
})

watch(necesitaImpresorasSistema, (needs) => {
  if (needs && impresoras.value.length === 0 && !impresorasLoading.value) {
    void cargarImpresoras()
  }
})

watch(tieneSeccionImpresoras, (v) => {
  if (v && impresoras.value.length === 0 && !impresorasLoading.value) {
    void cargarImpresoras()
  }
  if (v) void cargarPlantillas()
})

watch(empresaPlantillas, () => {
  if (tieneSeccionImpresoras.value) void cargarPlantillas()
})

function optionsFor(field: PuestoField) {
  if (field.optionsSource === 'trabajadores') return trabajadoresOptions.value
  if (field.optionsSource === 'usuarios') return usuariosOptions.value
  if (field.optionsSource === 'tiendas') return tiendasOptions.value
  if (field.optionsSource === 'impresoras-sistema') {
    return impresoras.value.map((p) => ({
      value: p.name,
      label: `${p.displayName || p.name}${p.isDefault ? ' (defecto)' : ''}`,
    }))
  }
  return []
}

function valorImpresoraField(field: PuestoField): string {
  return props.modelValue[field.key] != null ? String(props.modelValue[field.key]).trim() : ''
}

/** Solo nombres lógicos OPOS legacy (no Windows). TICKETW/TICKETU son reales. */
function esNombreLegacyOpos(nombre: string): boolean {
  const n = nombre.trim().toUpperCase()
  return n === 'TICKETS' || n === 'SLIP' || n === 'ETIQUETAS'
}

function esValorLegadoImpresora(field: PuestoField): boolean {
  return esNombreLegacyOpos(valorImpresoraField(field))
}

/** Opciones del desplegable: detectadas + valor actual si no está en la lista. */
function optionsImpresoraField(field: PuestoField): { value: string; label: string }[] {
  const detected = optionsFor(field)
  const actual = valorImpresoraField(field)
  if (!actual) return detected
  if (detected.some((o) => o.value === actual)) return detected
  const legado = esNombreLegacyOpos(actual)
  return [
    {
      value: actual,
      label: legado ? `${actual} (legado OPOS — elija otra)` : actual,
    },
    ...detected,
  ]
}

function onImpresoraSelect(field: PuestoField, raw: string) {
  const max = 100
  const v = String(raw ?? '').trim().slice(0, max)
  updateField(field.key, v || null)
}

function updateField(key: string, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

function isReadOnly(field: PuestoField) {
  return props.readonly || field.readOnly || (field.key === 'codigo' && props.codigoReadOnly)
}

function sectionClass(section: PuestoSection) {
  return `cols-${section.columns ?? 4}`
}

function indiceValue(key: string): number | null {
  const value = props.modelValue[key]
  if (value == null || value === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

function rowId(row: PuestoImpresoraDoc): string {
  return row.rowKey || row.indiceKey || row.nombreKey
}

function seleccionarDoc(row: PuestoImpresoraDoc) {
  if (props.readonly) return
  docSeleccionado.value = rowId(row)
}

function aplicarImpresora(printer: ImpresoraSistema) {
  if (props.readonly) return
  const key = docSeleccionado.value
  if (!key) {
    impresorasError.value = 'Seleccione antes un tipo de documento (fila izquierda).'
    return
  }
  const section = props.sections.find((s) => s.kind === 'impresoras')
  const row = section?.impresoras?.find((r) => rowId(r) === key)
  if (!row) return
  const max = row.nombreMax ?? 10
  // Nombres Windows reales (p.ej. ImpresoraTickets nvarchar(100)); no truncar a 8/10.
  const nombre = printer.name.slice(0, max)
  const next: Record<string, unknown> = {
    ...props.modelValue,
    [row.nombreKey]: nombre,
  }
  if (row.indiceKey) {
    next[row.indiceKey] = printer.id
  }
  emit('update:modelValue', next)
  impresorasError.value = null
}

function nombreResuelto(row: PuestoImpresoraDoc): string | null {
  if (row.indiceKey) {
    const id = indiceValue(row.indiceKey)
    if (id != null) {
      const fromList = impresoras.value.find((p) => p.id === id)
      if (fromList) return fromList.displayName || fromList.name
    }
  }
  const guardado = String(props.modelValue[row.nombreKey] ?? '').trim()
  if (!guardado) return null
  const hit = impresoras.value.find(
    (p) => p.name === guardado || String(p.displayName || '') === guardado
  )
  return hit ? hit.displayName || hit.name : guardado
}

function onNombreInput(row: PuestoImpresoraDoc, value: string) {
  const max = row.nombreMax ?? 10
  updateField(row.nombreKey, value.slice(0, max))
}
</script>

<template>
  <div class="tab-form" :class="{ 'tab-form-wide': sections.some((s) => s.kind === 'impresoras') }">
    <datalist id="puesto-impresoras-sistema">
      <option v-for="p in impresoras" :key="p.name" :value="p.name">
        {{ p.displayName || p.name }}
      </option>
    </datalist>
    <fieldset v-for="section in seccionesVisibles" :key="section.title" class="form-section">
      <legend>{{ section.title }}</legend>

      <div v-if="section.kind === 'impresoras'" class="impresoras-layout">
        <div class="impresoras-docs">
          <p class="docs-hint">
            En <strong>Plantilla</strong> elija un diseño de Configuración → Albaranes y facturas / Tickets / Etiquetas.
            La térmica de tickets se configura en <strong>Datos Generales</strong> (impresora Windows +
            copias), no aquí.
          </p>
          <p v-if="plantillasError" class="docs-hint warn">{{ plantillasError }}</p>
          <p v-else-if="plantillasLoading" class="docs-hint">Cargando plantillas…</p>
          <table class="imp-table" :class="{ 'con-plantilla': tieneFormato }">
            <thead>
              <tr>
                <th class="col-doc">Documento</th>
                <th class="col-ind">Ind.</th>
                <th class="col-imp">Impresora</th>
                <th v-if="tieneFormato" class="col-plt">Plantilla</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in section.impresoras ?? []"
                :key="rowId(row)"
                :class="{ selected: docSeleccionado === rowId(row) }"
                @click="seleccionarDoc(row)"
              >
                <td class="col-doc">{{ row.label }}</td>
                <td class="col-ind" @click.stop>
                  <DecimalInput
                    v-if="row.indiceKey"
                    :model-value="indiceValue(row.indiceKey)"
                    :empty-as-null="true"
                    :readonly="readonly"
                    @update:model-value="updateField(row.indiceKey!, $event)"
                  />
                  <span v-else class="sin-plt">—</span>
                </td>
                <td class="col-imp" @click.stop>
                  <input
                    type="text"
                    :value="String(modelValue[row.nombreKey] ?? '')"
                    :readonly="readonly"
                    :maxlength="row.nombreMax ?? 10"
                    :class="{
                      'imp-legacy': esNombreLegacyOpos(String(modelValue[row.nombreKey] ?? '')),
                    }"
                    :title="nombreResuelto(row) || String(modelValue[row.nombreKey] ?? '')"
                    @input="onNombreInput(row, ($event.target as HTMLInputElement).value)"
                  />
                </td>
                <td v-if="tieneFormato" class="col-plt" @click.stop>
                  <template v-if="row.formatoKey && row.plantillaTipo">
                    <select
                      :value="formatoActual(row)"
                      :disabled="readonly || plantillasLoading"
                      @change="onFormatoChange(row, ($event.target as HTMLSelectElement).value)"
                    >
                      <option value="">— Sin plantilla —</option>
                      <option
                        v-for="p in plantillasPara(row)"
                        :key="p.id"
                        :value="p.nombre"
                      >
                        {{ etiquetaPlantilla(p, row) }}
                      </option>
                      <option
                        v-if="formatoActual(row) && !plantillasPara(row).some((p) => p.nombre === formatoActual(row))"
                        :value="formatoActual(row)"
                      >
                        {{ formatoActual(row) }} (guardada)
                      </option>
                    </select>
                  </template>
                  <span v-else class="sin-plt">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <aside class="impresoras-sistema">
          <div class="sistema-cabecera">
            <h4>Impresoras del sistema</h4>
            <button
              type="button"
              class="btn-refrescar"
              :disabled="impresorasLoading"
              @click="cargarImpresoras"
            >
              {{ impresorasLoading ? 'Detectando…' : 'Actualizar' }}
            </button>
          </div>
          <p class="sistema-hint">
            Detectadas en este equipo
            <span v-if="impresorasOnline" class="badge-ok">en linea</span>
            <span v-else class="badge-off">sin agente</span>
          </p>

          <div v-if="impresorasLoading && !impresoras.length" class="sistema-lista sistema-vacia">
            <p>Detectando impresoras…</p>
          </div>

          <div v-else-if="impresoras.length" class="sistema-lista">
            <button
              v-for="p in impresoras"
              :key="`${p.id}-${p.name}`"
              type="button"
              class="printer-row"
              :disabled="readonly"
              :title="readonly ? '' : 'Asignar al documento seleccionado'"
              @click="aplicarImpresora(p)"
            >
              <span class="printer-id">{{ p.id }}</span>
              <span class="printer-name">
                {{ p.displayName || p.name }}
                <em v-if="p.isDefault"> (defecto)</em>
              </span>
            </button>
          </div>

          <div v-else class="sistema-lista sistema-vacia">
            <p>{{ impresorasError || 'Sin impresoras detectadas.' }}</p>
            <p class="sistema-sub">Ejecute Descartes Electron en este PC y pulse Actualizar.</p>
          </div>

          <p v-if="impresorasError && impresoras.length" class="sistema-error">{{ impresorasError }}</p>
        </aside>
      </div>

      <div v-else class="section-grid" :class="sectionClass(section)">
        <div
          v-for="field in section.fields ?? []"
          :key="field.key"
          class="field"
          :class="[
            `span-${field.span ?? 1}`,
            field.layout === 'inline' ? 'field-inline' : '',
            field.layout === 'checkbox' ? 'field-checkbox' : '',
            field.type === 'number' ? 'field-number' : '',
          ]"
        >
          <span class="field-label">{{ field.label }}<em v-if="field.required"> *</em></span>

          <input
            v-if="field.type === 'checkbox'"
            type="checkbox"
            :checked="Boolean(modelValue[field.key])"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLInputElement).checked)"
          />

          <div v-else-if="field.type === 'select' && field.optionsSource === 'impresoras-sistema'" class="imp-sistema-field">
            <div class="imp-sistema-row">
              <!-- Texto editable: no depende de que el agente liste impresoras. -->
              <input
                type="text"
                list="puesto-impresoras-sistema"
                :value="valorImpresoraField(field)"
                :readonly="isReadOnly(field)"
                :class="{ 'imp-legacy-select': esValorLegadoImpresora(field) }"
                maxlength="100"
                placeholder="Nombre Windows (p. ej. TICKETW)"
                autocomplete="off"
                @change="onImpresoraSelect(field, ($event.target as HTMLInputElement).value)"
                @blur="onImpresoraSelect(field, ($event.target as HTMLInputElement).value)"
              />
              <select
                class="imp-sistema-pick"
                value=""
                :disabled="isReadOnly(field) || !impresoras.length"
                title="Elegir de las detectadas"
                @change="
                  onImpresoraSelect(field, ($event.target as HTMLSelectElement).value);
                  ($event.target as HTMLSelectElement).value = ''
                "
              >
                <option value="" disabled>
                  {{ impresoras.length ? 'Elegir…' : 'Sin lista' }}
                </option>
                <option v-for="opt in optionsImpresoraField(field)" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
              <button
                type="button"
                class="btn-refrescar"
                :disabled="impresorasLoading"
                title="Detectar impresoras Windows de este equipo"
                @click="cargarImpresoras"
              >
                {{ impresorasLoading ? '…' : '↻' }}
              </button>
            </div>
            <p v-if="isReadOnly(field)" class="imp-sistema-hint">
              Pulse <strong>Modificar</strong> para cambiar. Puede escribir el nombre Windows a mano.
            </p>
            <p v-else-if="esValorLegadoImpresora(field)" class="imp-sistema-hint warn">
              «{{ valorImpresoraField(field) }}» es un nombre lógico OPOS. Sustitúyalo por TICKETW,
              TICKETU u otra impresora Windows y guarde.
            </p>
            <p v-else-if="impresorasError && !impresoras.length" class="imp-sistema-hint warn">
              {{ impresorasError }} Puede escribir el nombre a mano (p. ej. TICKETW).
            </p>
            <p v-else-if="!impresoras.length && !impresorasLoading" class="imp-sistema-hint warn">
              Lista no detectada. Escriba el nombre Windows o ejecute Electron y pulse ↻.
            </p>
            <p v-else-if="impresoras.length" class="imp-sistema-hint ok">
              {{ impresoras.length }} detectada(s) — o escriba el nombre a mano
            </p>
          </div>

          <EntidadLookupField
            v-else-if="entidadDesdeOptionsSource(field.optionsSource)"
            :model-value="(modelValue[field.key] as string | number | null) ?? null"
            :entidad="entidadDesdeOptionsSource(field.optionsSource)!"
            :readonly="isReadOnly(field)"
            :max-length="field.maxLength"
            :field-key="field.key"
            empty-as-null
            @update:model-value="updateField(field.key, $event)"
          />

          <select
            v-else-if="field.type === 'select'"
            :value="modelValue[field.key] != null ? String(modelValue[field.key]).trim() : ''"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLSelectElement).value || null)"
          >
            <option value="">--</option>
            <option
              v-if="
                modelValue[field.key] &&
                !optionsFor(field).some((o) => o.value === String(modelValue[field.key]).trim())
              "
              :value="String(modelValue[field.key]).trim()"
            >
              {{ modelValue[field.key] }} (legado / no en lista)
            </option>
            <option v-for="opt in optionsFor(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>

          <DecimalInput
            v-else-if="field.type === 'number'"
            :model-value="(modelValue[field.key] as number | null) ?? null"
            :empty-as-null="true"
            :readonly="isReadOnly(field)"
            @update:model-value="updateField(field.key, $event)"
          />

          <input
            v-else
            type="text"
            :value="String(modelValue[field.key] ?? '')"
            :readonly="isReadOnly(field)"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>
    </fieldset>
  </div>
</template>

<style scoped>
.tab-form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.5rem 0.65rem 0.65rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 0 0 8px 8px;
  width: 100%;
  max-width: none;
  box-sizing: border-box;
}

.form-section {
  margin: 0;
  padding: 0.35rem 0.5rem 0.45rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
  min-width: 0;
}

.form-section legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}

.impresoras-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(17rem, 34%);
  gap: 0.65rem;
  align-items: stretch;
}

.impresoras-docs {
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.docs-hint {
  margin: 0 0 0.35rem;
  font-size: 0.72rem;
  color: #64748b;
}

.docs-hint.warn {
  color: #b45309;
}

.imp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}

.imp-table th,
.imp-table td {
  border: 1px solid #cbd5e1;
  padding: 0.2rem 0.35rem;
  vertical-align: middle;
}

.imp-table th {
  background: #e8eef5;
  font-weight: 600;
  text-align: left;
  color: #334155;
}

.imp-table tbody tr {
  cursor: pointer;
}

.imp-table tbody tr:hover {
  background: #f8fafc;
}

.imp-table tbody tr.selected {
  background: #e0f2fe;
}

.imp-table .col-doc {
  width: 28%;
  white-space: nowrap;
}

.imp-table.con-plantilla .col-doc {
  width: 22%;
}

.imp-table .col-ind {
  width: 4.5rem;
  text-align: center;
}

.imp-table .col-ind :deep(input) {
  width: 3.25rem;
  max-width: 3.25rem;
  text-align: right;
}

.imp-table .col-imp {
  width: 28%;
}

.imp-table .col-imp input {
  width: 100%;
  box-sizing: border-box;
  font: inherit;
  font-size: 0.75rem;
  padding: 0.15rem 0.3rem;
  border: 1px solid #cbd5e1;
  border-radius: 3px;
}

.imp-table .col-imp input.imp-legacy {
  border-color: #f59e0b;
  background: #fffbeb;
  color: #92400e;
}

.imp-sistema-field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
}

.imp-sistema-row {
  display: flex;
  gap: 0.35rem;
  align-items: stretch;
  min-width: 0;
}

.imp-sistema-row select {
  flex: 1;
  min-width: 0;
  font: inherit;
  font-size: 0.78rem;
  padding: 0.2rem 0.35rem;
  border: 1px solid #cbd5e1;
  border-radius: 3px;
  background: #fff;
}

.imp-sistema-row input[type='text'] {
  flex: 1.4;
  min-width: 0;
  font: inherit;
  font-size: 0.78rem;
  padding: 0.2rem 0.35rem;
  border: 1px solid #cbd5e1;
  border-radius: 3px;
  background: #fff;
}

.imp-sistema-row input[type='text']:read-only {
  background: #f8fafc;
  color: #334155;
}

.imp-sistema-row .imp-sistema-pick {
  flex: 0 0 6.5rem;
  max-width: 6.5rem;
}

.imp-sistema-row select.imp-legacy-select,
.imp-sistema-row input.imp-legacy-select {
  border-color: #f59e0b;
  background: #fffbeb;
  color: #92400e;
}

.imp-sistema-row .btn-refrescar {
  flex: 0 0 auto;
  min-width: 2rem;
  padding: 0 0.45rem;
}

.imp-sistema-hint {
  margin: 0;
  font-size: 0.68rem;
  color: #64748b;
  line-height: 1.25;
}

.imp-sistema-hint.warn {
  color: #b45309;
}

.imp-sistema-hint.ok {
  color: #0f766e;
}

.imp-table .col-plt {
  width: 32%;
  min-width: 8rem;
}

.imp-table .col-plt select,
.imp-table .col-plt input {
  width: 100%;
  box-sizing: border-box;
  font: inherit;
  font-size: 0.75rem;
  padding: 0.15rem 0.3rem;
  border: 1px solid #cbd5e1;
  border-radius: 3px;
  background: #fff;
}

.imp-table .sin-plt {
  color: #94a3b8;
}

.impresoras-sistema {
  display: flex;
  flex-direction: column;
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #f8fafc;
  padding: 0.45rem 0.55rem;
  min-height: 100%;
}

.sistema-cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
  margin-bottom: 0.25rem;
}

.impresoras-sistema h4 {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 600;
  color: #334155;
}

.btn-refrescar {
  padding: 0.15rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  font-size: 0.72rem;
  cursor: pointer;
}

.btn-refrescar:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.sistema-hint {
  margin: 0 0 0.45rem;
  font-size: 0.72rem;
  color: #64748b;
  line-height: 1.35;
}

.badge-ok,
.badge-off {
  display: inline-block;
  margin-left: 0.25rem;
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
  font-size: 0.65rem;
  font-weight: 600;
}

.badge-ok {
  background: #d1fae5;
  color: #065f46;
}

.badge-off {
  background: #fee2e2;
  color: #991b1b;
}

.sistema-lista {
  flex: 1 1 auto;
  border: 1px solid #cbd5e1;
  border-radius: 3px;
  background: #fff;
  min-height: 14rem;
  max-height: none;
  overflow: auto;
}

.sistema-vacia {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0.25rem;
  min-height: 8rem;
  padding: 0.65rem 0.5rem;
  text-align: center;
  color: #64748b;
  font-size: 0.75rem;
  border-style: dashed;
}

.sistema-sub {
  margin: 0;
  font-size: 0.7rem;
  color: #94a3b8;
}

.sistema-error {
  margin: 0.35rem 0 0;
  font-size: 0.72rem;
  color: #b91c1c;
}

.printer-row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  padding: 0.3rem 0.4rem;
  border: 0;
  border-bottom: 1px solid #e2e8f0;
  background: transparent;
  text-align: left;
  cursor: pointer;
  font-size: 0.75rem;
}

.printer-row:last-child {
  border-bottom: 0;
}

.printer-row:hover:not(:disabled) {
  background: #e0f2fe;
}

.printer-row:disabled {
  cursor: default;
  opacity: 0.7;
}

.printer-id {
  flex: 0 0 1.75rem;
  font-weight: 700;
  color: #334155;
  font-variant-numeric: tabular-nums;
}

.printer-name {
  flex: 1;
  min-width: 0;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.printer-name em {
  font-style: normal;
  color: #047857;
  font-size: 0.7rem;
}

.section-grid {
  display: grid;
  gap: 0.2rem 0.5rem;
  align-items: center;
}

.section-grid.cols-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.section-grid.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.section-grid.cols-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.field {
  min-width: 0;
}

.field.span-2 {
  grid-column: span 2;
}

.field.span-3 {
  grid-column: span 3;
}

.field.span-4 {
  grid-column: span 4;
}

.field-inline {
  display: grid;
  /* Ancho fijo de etiqueta: inputs izquierda/derecha misma medida */
  grid-template-columns: 9.75rem minmax(0, 1fr);
  gap: 0.35rem;
  align-items: center;
}

.field-inline .field-label {
  font-size: 0.78rem;
  color: #475569;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.field-inline > input,
.field-inline > select,
.field-inline > :deep(.decimal-input),
.field-inline > .imp-sistema-field {
  width: 100%;
  min-width: 0;
  max-width: none;
}

.field-checkbox {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.1rem 0;
}

.field-checkbox .field-label {
  order: 2;
  font-size: 0.78rem;
  color: #475569;
  line-height: 1.2;
}

.field-checkbox input[type='checkbox'] {
  order: 1;
  margin: 0;
  flex-shrink: 0;
}

.field-number input {
  max-width: 5rem;
}

.field.required .field-label em {
  color: #b91c1c;
  font-style: normal;
}

input,
select {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  font-size: 0.8rem;
  line-height: 1.25;
}

input[type='checkbox'] {
  width: 0.9rem;
  height: 0.9rem;
}

input:read-only,
select:disabled {
  background: #e8edf2;
  color: #475569;
}

@media (max-width: 900px) {
  .impresoras-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 800px) {
  .section-grid.cols-4,
  .section-grid.cols-3 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .field.span-3,
  .field.span-4 {
    grid-column: span 2;
  }
}
</style>
