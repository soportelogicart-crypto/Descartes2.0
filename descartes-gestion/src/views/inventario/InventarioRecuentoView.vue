<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import {
  actualizarRecuento,
  anotarRecuento,
  congelarRecuento,
  descartarRecuento,
  obtenerRecuento,
  type FiltroCongelacion,
  type InventarioEstado,
  type InventarioLinea,
} from '@/api/inventario'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import { extractApiError } from '@/composables/extractApiError'
import type { EntidadLookupId } from '@/config/entidad-lookup'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

const puesto = usePuestoContextoStore()
const { puede } = usePermisos()

const cargando = ref(false)
const guardando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const estado = ref<InventarioEstado | null>(null)
const almacen = ref(0)
const filtros = ref<FiltroCongelacion>({
  familiaDesde: '',
  familiaHasta: '',
  subfamiliaDesde: '',
  subfamiliaHasta: '',
  agrupacionDesde: '',
  agrupacionHasta: '',
  articuloDesde: '',
  articuloHasta: '',
  proveedorDesde: '',
  proveedorHasta: '',
  excluirBajas: true,
  reservas: 'no',
})
const articulo = ref('')
const cantidad = ref('1')
const soloDiferencias = ref(false)
const articuloInput = ref<HTMLInputElement | null>(null)

const puedeCrear = computed(() => puede('inventario', 'crear'))
const puedeEditar = computed(() => puede('inventario', 'editar'))
const empresa = computed(() => String(puesto.empresaCodigo ?? '').trim())

const rangos = [
  { key: 'familia', label: 'Familia', entidad: 'familias' },
  { key: 'subfamilia', label: 'Subfamilia', entidad: 'subfamilias' },
  { key: 'agrupacion', label: 'Agrupación', entidad: 'agrupaciones' },
  { key: 'articulo', label: 'Artículo', entidad: 'articulos' },
  { key: 'proveedor', label: 'Proveedor', entidad: 'proveedores' },
] as const satisfies readonly { key: string; label: string; entidad: EntidadLookupId }[]

type RangoKey = (typeof rangos)[number]['key']

const lineasVisibles = computed(() => {
  const lineas = estado.value?.lineas ?? []
  if (!soloDiferencias.value) return lineas
  return lineas.filter((l) => Math.abs(l.diferencia) > 0.0001)
})

function uds(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function valorRango(key: RangoKey, lado: 'Desde' | 'Hasta'): string {
  return String(filtros.value[`${key}${lado}` as keyof FiltroCongelacion] ?? '')
}

function setRango(key: RangoKey, lado: 'Desde' | 'Hasta', valor: string) {
  const campo = `${key}${lado}` as keyof FiltroCongelacion
  ;(filtros.value as Record<string, string | boolean>)[campo] = valor
}

const buscarOpen = ref(false)
const buscarEntidad = ref<EntidadLookupId>('familias')
const buscarCampo = ref<keyof FiltroCongelacion>('familiaDesde')
const buscarInicial = ref('')

function abrirBuscar(key: RangoKey, entidad: EntidadLookupId, lado: 'Desde' | 'Hasta') {
  const campo = `${key}${lado}` as keyof FiltroCongelacion
  buscarCampo.value = campo
  buscarEntidad.value = entidad
  buscarInicial.value = valorRango(key, lado)
  buscarOpen.value = true
}

function onEntidadSeleccionada(r: EntidadBuscarResultado) {
  const codigo = r.codigo.trim()
  const campo = buscarCampo.value
  ;(filtros.value as Record<string, string | boolean>)[campo] = codigo
  if (String(campo).endsWith('Desde')) {
    const hasta = `${String(campo).slice(0, -5)}Hasta` as keyof FiltroCongelacion
    ;(filtros.value as Record<string, string | boolean>)[hasta] = codigo
  }
  buscarOpen.value = false
}

function aplicarEstado(data: InventarioEstado) {
  estado.value = data
  if (data.almacen > 0) almacen.value = data.almacen
  if (data.mensaje) mensaje.value = data.mensaje
}

async function cargar() {
  cargando.value = true
  error.value = null
  try {
    aplicarEstado(await obtenerRecuento(empresa.value, almacen.value))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el recuento')
  } finally {
    cargando.value = false
  }
}

async function congelar() {
  if (!puedeCrear.value) {
    error.value = 'Sin permiso para congelar el stock'
    return
  }
  if (almacen.value <= 0) {
    error.value = 'Elija el almacén'
    return
  }
  const hay = (estado.value?.resumen.filas ?? 0) > 0
  if (
    !window.confirm(
      hay
        ? 'Ya hay un recuento. Se sustituye por el stock actual y las cantidades contadas se pierden. ¿Continuar?'
        : 'Se congela el stock del almacén. Lo que no se cuente se regularizará a cero al actualizar. ¿Continuar?'
    )
  ) {
    return
  }
  guardando.value = true
  error.value = null
  mensaje.value = null
  try {
    aplicarEstado(await congelarRecuento(empresa.value, almacen.value, hay, filtros.value))
    await enfocarArticulo()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo congelar el stock')
  } finally {
    guardando.value = false
  }
}

async function anotar() {
  if (!puedeCrear.value) {
    error.value = 'Sin permiso para contar'
    return
  }
  if (almacen.value <= 0) {
    error.value = 'Elija el almacén'
    return
  }
  guardando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await anotarRecuento(empresa.value, almacen.value, articulo.value, cantidad.value)
    aplicarEstado(data)
    mensaje.value = data.articulo
      ? `${data.articulo}: contado ${uds(Number(data.contado ?? 0))}`
      : null
    articulo.value = ''
    cantidad.value = '1'
    await enfocarArticulo()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo anotar el artículo')
  } finally {
    guardando.value = false
  }
}

async function descartar() {
  if (!puedeEditar.value) {
    error.value = 'Sin permiso para descartar el recuento'
    return
  }
  if ((estado.value?.resumen.filas ?? 0) <= 0) {
    error.value = 'No hay recuento que descartar'
    return
  }
  if (!window.confirm('Se borra el recuento de este almacén. El stock no cambia. ¿Continuar?')) {
    return
  }
  guardando.value = true
  error.value = null
  mensaje.value = null
  try {
    aplicarEstado(await descartarRecuento(empresa.value, almacen.value))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo descartar el recuento')
  } finally {
    guardando.value = false
  }
}

async function actualizar() {
  if (!puedeEditar.value) {
    error.value = 'Sin permiso para actualizar el stock'
    return
  }
  const resumen = estado.value?.resumen
  if (!resumen || resumen.filas <= 0) {
    error.value = 'No hay recuento que actualizar'
    return
  }
  if (
    !window.confirm(
      `Se regulariza el stock de ${resumen.conDiferencia} artículos` +
        (resumen.sinContar > 0
          ? `.\n${resumen.sinContar} sin contar pasarán a cero.`
          : '.') +
        '\nDespués se cierra el recuento. ¿Continuar?'
    )
  ) {
    return
  }
  guardando.value = true
  error.value = null
  mensaje.value = null
  try {
    const result = await actualizarRecuento(empresa.value, almacen.value)
    mensaje.value = result.mensaje
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo actualizar el stock')
  } finally {
    guardando.value = false
  }
}

async function enfocarArticulo() {
  await nextTick()
  articuloInput.value?.focus()
  articuloInput.value?.select()
}

function claseFila(lin: InventarioLinea): string {
  if (Math.abs(lin.contado) <= 0.0001) return 'sin-contar'
  if (Math.abs(lin.diferencia) > 0.0001) return 'con-dif'
  return ''
}

onMounted(async () => {
  await cargar()
  await enfocarArticulo()
})
</script>

<template>
  <section class="ventas-view inventario-view">
    <div class="head head-compact">
      <div>
        <h2>Recuento de inventario</h2>
        <p class="hint">
          Congela el stock, anota lo contado y actualiza la diferencia. Lo no contado se da por cero.
        </p>
      </div>
      <div class="head-actions">
        <button
          type="button"
          class="btn-accion"
          :disabled="cargando || guardando || !puedeEditar"
          @click="descartar"
        >
          Descartar
        </button>
        <button
          type="button"
          class="btn-accion btn-primary"
          :disabled="cargando || guardando || !puedeEditar"
          @click="actualizar"
        >
          {{ guardando ? 'Procesando…' : 'Actualizar stock' }}
        </button>
      </div>
    </div>

    <div class="layout-busqueda layout-inventario">
      <form class="panel-filtros panel-filtros-compact" @submit.prevent="congelar">
        <fieldset class="bloque-opciones opciones-fila">
          <legend>Opciones</legend>
          <label>
            <span>Almacén</span>
            <select v-model.number="almacen" :disabled="cargando || guardando" @change="cargar">
              <option :value="0">—</option>
              <option v-for="a in estado?.almacenes ?? []" :key="a.codigo" :value="a.codigo">
                {{ a.codigo }} — {{ a.descripcion }}
              </option>
            </select>
          </label>
          <label>
            <span>Excluir bajas</span>
            <select v-model="filtros.excluirBajas" :disabled="guardando">
              <option :value="true">Sí</option>
              <option :value="false">No</option>
            </select>
          </label>
          <label>
            <span>Reservas</span>
            <select v-model="filtros.reservas" :disabled="guardando">
              <option value="no">No añadir</option>
              <option value="inventariadas">Añadir como inventariadas</option>
            </select>
          </label>
        </fieldset>

        <fieldset class="bloque-intervalos bloque-intervalos-col">
          <legend>Intervalos</legend>
          <div class="intervalos-col">
            <div class="rango-head">
              <span />
              <span>Desde</span>
              <span>Hasta</span>
            </div>
            <div v-for="row in rangos" :key="row.key" class="rango-row">
              <span class="rango-label">{{ row.label }}</span>
              <div class="celda-intervalo">
                <div class="con-lupa">
                  <input
                    :value="valorRango(row.key, 'Desde')"
                    type="text"
                    maxlength="20"
                    autocomplete="off"
                    :disabled="guardando"
                    @input="setRango(row.key, 'Desde', ($event.target as HTMLInputElement).value)"
                  />
                  <button
                    type="button"
                    class="btn-lupa"
                    :title="`Buscar ${row.label} desde`"
                    :disabled="guardando"
                    @click="abrirBuscar(row.key, row.entidad, 'Desde')"
                  >
                    <ToolIcon name="buscar" />
                  </button>
                </div>
              </div>
              <div class="celda-intervalo">
                <div class="con-lupa">
                  <input
                    :value="valorRango(row.key, 'Hasta')"
                    type="text"
                    maxlength="20"
                    autocomplete="off"
                    :disabled="guardando"
                    @input="setRango(row.key, 'Hasta', ($event.target as HTMLInputElement).value)"
                  />
                  <button
                    type="button"
                    class="btn-lupa"
                    :title="`Buscar ${row.label} hasta`"
                    :disabled="guardando"
                    @click="abrirBuscar(row.key, row.entidad, 'Hasta')"
                  >
                    <ToolIcon name="buscar" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </fieldset>

        <button
          type="submit"
          class="btn-buscar btn-buscar-compact"
          :disabled="cargando || guardando || !puedeCrear"
        >
          {{ guardando ? 'Congelando…' : 'Congelar stock' }}
        </button>
      </form>

      <div class="panel-listado">
        <form class="barra-conteo" @submit.prevent="anotar">
          <label>
            <span>Artículo / EAN</span>
            <input
              ref="articuloInput"
              v-model="articulo"
              type="text"
              autocomplete="off"
              :disabled="guardando"
            />
          </label>
          <label>
            <span>Cantidad</span>
            <input
              v-model="cantidad"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              :disabled="guardando"
            />
          </label>
          <button type="submit" class="btn-accion" :disabled="guardando || !puedeCrear">Añadir</button>
          <label class="check">
            <input v-model="soloDiferencias" type="checkbox" />
            <span>Solo con diferencia</span>
          </label>
        </form>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
        <p v-if="estado" class="resumen">
          {{ estado.resumen.filas }} artículos · {{ estado.resumen.sinContar }} sin contar ·
          {{ estado.resumen.conDiferencia }} con diferencia
        </p>

        <div v-if="lineasVisibles.length" class="grid-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th>Artículo</th>
                <th>Descripción</th>
                <th class="num">Congelado</th>
                <th class="num">Contado</th>
                <th class="num">Diferencia</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="lin in lineasVisibles" :key="lin.articulo" :class="claseFila(lin)">
                <td>{{ lin.articulo }}</td>
                <td>{{ lin.descripcion }}</td>
                <td class="num">{{ uds(lin.congelado) }}</td>
                <td class="num">{{ uds(lin.contado) }}</td>
                <td class="num">{{ uds(lin.diferencia) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!cargando" class="empty">
          No hay líneas. Congele el stock o anote un artículo.
        </p>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarOpen"
      :entidad="buscarEntidad"
      :busqueda-inicial="buscarInicial"
      @seleccionar="onEntidadSeleccionada"
      @cerrar="buscarOpen = false"
    />
  </section>
</template>

<style scoped>
@import '../listados/listado-informe-layout.css';
@import '../listados/listado-grid.css';

.inventario-view {
  --stock-col-etiq: 6.25rem;
}

.head-compact {
  margin-bottom: 0.35rem;
}

.head-compact h2 {
  font-size: 1.05rem;
}

.btn-primary {
  background: #1e40af;
  border-color: #1e40af;
  color: #fff;
}

.layout-inventario {
  grid-template-columns: minmax(17rem, 22rem) minmax(0, 1fr);
  align-items: start;
}

.panel-filtros-compact {
  max-height: none;
  overflow: visible;
  padding: 0.35rem 0.4rem;
  gap: 0.3rem;
}

.panel-filtros-compact fieldset {
  padding: 0.3rem 0.35rem 0.35rem;
}

.panel-filtros-compact legend {
  font-size: 0.68rem;
}

.opciones-fila {
  display: flex;
  flex-direction: column;
  gap: 0.22rem;
}

.opciones-fila label {
  display: grid !important;
  grid-template-columns: var(--stock-col-etiq) minmax(0, 1fr);
  align-items: center;
  gap: 0.35rem;
  flex-direction: row !important;
  font-size: 0.75rem !important;
  color: #475569 !important;
}

.opciones-fila label > span {
  text-align: right;
  line-height: 1.2;
}

.opciones-fila select {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.2rem 0.35rem !important;
  font-size: 0.78rem !important;
}

.bloque-intervalos-col {
  padding-left: 0;
  padding-right: 0;
}

.intervalos-col {
  display: grid;
  grid-template-columns: var(--stock-col-etiq) minmax(0, 1fr) minmax(0, 1fr);
  column-gap: 0.35rem;
  row-gap: 0.22rem;
  align-items: center;
  width: 100%;
}

.intervalos-col .rango-head,
.intervalos-col .rango-row {
  display: contents;
}

.intervalos-col .rango-head span:nth-child(2),
.intervalos-col .rango-head span:nth-child(3) {
  font-size: 0.72rem;
  color: #64748b;
  text-align: center;
  padding-bottom: 0.05rem;
}

.intervalos-col .rango-label {
  text-align: right;
  font-size: 0.75rem;
  line-height: 1.2;
  color: #475569;
  padding-right: 0.05rem;
}

.celda-intervalo {
  min-width: 0;
  display: flex;
  align-items: center;
}

.celda-intervalo input {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  font-size: 0.78rem;
  line-height: 1.25;
  background: #fff;
}

.intervalos-col .con-lupa {
  width: 100%;
}

.intervalos-col .btn-lupa {
  width: 1.35rem;
  height: 1.35rem;
}

.intervalos-col .btn-lupa:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn-buscar-compact {
  margin-top: 0.1rem;
  padding: 0.32rem 0.55rem;
  font-size: 0.82rem;
}

.panel-listado {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  min-width: 0;
  min-height: 0;
}

.barra-conteo {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem 0.65rem;
  align-items: end;
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
}

.barra-conteo label {
  display: grid;
  gap: 0.12rem;
  font-size: 0.75rem;
  color: #475569;
}

.barra-conteo input[type='text'] {
  padding: 0.28rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  min-width: 8rem;
  font: inherit;
  font-size: 0.85rem;
}

.barra-conteo .check {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.8rem;
  margin-bottom: 0.15rem;
}

.resumen {
  margin: 0;
  color: #334155;
  font-size: 0.82rem;
}

.error {
  color: #b91c1c;
  margin: 0;
  font-size: 0.85rem;
}

.ok {
  color: #047857;
  margin: 0;
  font-size: 0.85rem;
}

.grid-wrap {
  flex: 1;
  min-height: 8rem;
  max-height: calc(100vh - 16rem);
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}

.sin-contar td {
  background: #fff7ed;
}

.con-dif td {
  background: #eff6ff;
}

.empty {
  color: #64748b;
  font-size: 0.85rem;
  margin: 0.5rem 0 0;
}

@media (max-width: 900px) {
  .layout-inventario {
    grid-template-columns: 1fr;
  }
}
</style>
