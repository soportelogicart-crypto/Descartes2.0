<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import DecimalInput from '@/components/common/DecimalInput.vue'
import FidelizacionArbolExclusion from '@/components/fidelizacion/FidelizacionArbolExclusion.vue'
import {
  estadoAutomaticoFidelizacion,
  generarValesFidelizacion,
  guardarPuntosFidelizacion,
  guardarSemestreFidelizacion,
  marcarTiendaSinPuntos,
  obtenerConfiguracionFidelizacion,
  seleccionarModeloFidelizacion,
  semestreFidelizacion,
  type FidelizacionConfiguracion,
  type FidelizacionExclusion,
  type FidelizacionEstadoAutomatico,
  type FidelizacionValesSemestreResultado,
} from '@/api/ventas'
import { extractApiError } from '@/composables/extractApiError'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

const { puede } = usePermisos()
const puesto = usePuestoContextoStore()

const empresa = ref('')
const fechaInicio = ref('')
const fechaFin = ref('')
const pjeCanje = ref<number | null>(3)
const resultado = ref<FidelizacionValesSemestreResultado | null>(null)
const cargando = ref(false)
const error = ref('')
const mensaje = ref('')
const forzar = ref(false)
const configuracion = ref<FidelizacionConfiguracion | null>(null)
const estadoAutomatico = ref<FidelizacionEstadoAutomatico | null>(null)
const seleccionando = ref(false)
const guardandoTienda = ref('')
const guardandoPuntos = ref(false)

const puntos = ref({
  porcentaje: 20 as number | null,
  importeMinimo: 0 as number | null,
  exclusiones: [] as FidelizacionExclusion[],
  multiplo: 1 as number | null,
  valorPunto: 0.2 as number | null,
})
const editandoPuntos = ref(false)
const editandoSemestre = ref(false)

const esPuntos = computed(() => modeloSeleccionado.value?.motor === 'PUNTOS')
const puedeEditarPuntos = computed(() => puede('clientes', 'editar'))
const soloLecturaPuntos = computed(() => !editandoPuntos.value)

const modeloSeleccionado = computed(() =>
  configuracion.value?.modelos.find((m) => m.codigo === configuracion.value?.seleccionado)
)
const esSemestral = computed(() => modeloSeleccionado.value?.motor === 'VALE_SEMESTRAL')

onMounted(async () => {
  empresa.value = puesto.empresaCodigo ?? ''
  try {
    const [sem, config, estado] = await Promise.all([
      semestreFidelizacion(),
      obtenerConfiguracionFidelizacion(empresa.value),
      estadoAutomaticoFidelizacion(empresa.value),
    ])
    fechaInicio.value = sem.inicio
    fechaFin.value = sem.fin
    configuracion.value = config
    rellenarPuntos()
    estadoAutomatico.value = estado
    pjeCanje.value = Number(modeloSeleccionado.value?.configuracion.pjeCanje ?? 3)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar la configuración de fidelización')
  }
})

function rellenarPuntos() {
  const modelo = modeloSeleccionado.value
  if (!modelo || modelo.motor !== 'PUNTOS') return
  const c = modelo.configuracion ?? {}
  const factor = Number(modelo.factor) || 0
  puntos.value = {
    porcentaje: Number(c.porcentaje ?? (factor > 0 ? Math.round(factor * 10000) / 100 : 20)),
    importeMinimo: Number(c.importeMinimo ?? 0),
    exclusiones: Array.isArray(c.exclusiones) ? c.exclusiones.map((e) => ({ ...e })) : [],
    multiplo: Number(c.multiplo ?? 1) || 1,
    valorPunto: Number(c.valorPunto ?? 0.2),
  }
}

const exclusionesSemestre = ref<FidelizacionExclusion[]>([])

function rellenarSemestre() {
  const guardadas = modeloSeleccionado.value?.configuracion.exclusiones
  pjeCanje.value = Number(modeloSeleccionado.value?.configuracion.pjeCanje ?? 3)
  exclusionesSemestre.value = Array.isArray(guardadas) ? guardadas.map((e) => ({ ...e })) : []
}

watch(modeloSeleccionado, () => {
  editandoPuntos.value = false
  editandoSemestre.value = false
  rellenarPuntos()
  rellenarSemestre()
})

function modificarSemestre() {
  editandoSemestre.value = true
  mensaje.value = ''
  error.value = ''
}

function cancelarSemestre() {
  editandoSemestre.value = false
  rellenarSemestre()
}

async function guardarSemestre() {
  const modelo = modeloSeleccionado.value
  if (!modelo || modelo.motor !== 'VALE_SEMESTRAL') return
  cargando.value = true
  error.value = ''
  mensaje.value = ''
  try {
    const guardado = await guardarSemestreFidelizacion({
      codigo: modelo.codigo,
      pjeCanje: Number(pjeCanje.value) || 3,
      exclusiones: exclusionesSemestre.value,
    })
    modelo.configuracion = { ...modelo.configuracion, ...guardado }
    editandoSemestre.value = false
    rellenarSemestre()
    mensaje.value = 'Configuración del vale semestral guardada.'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar la configuración del vale')
  } finally {
    cargando.value = false
  }
}

function modificarPuntos() {
  editandoPuntos.value = true
  mensaje.value = ''
  error.value = ''
}

function cancelarPuntos() {
  editandoPuntos.value = false
  rellenarPuntos()
}

function euros(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
}

async function lanzar(simular: boolean) {
  error.value = ''
  mensaje.value = ''
  if (!empresa.value.trim()) {
    error.value = 'Indique la empresa'
    return
  }
  cargando.value = true
  try {
    const data = await generarValesFidelizacion({
      empresa: empresa.value.trim(),
      fechaInicio: fechaInicio.value,
      fechaFin: fechaFin.value,
      pjeCanje: Number(pjeCanje.value) || 3,
      simular,
      forzar: forzar.value,
    })
    resultado.value = data
    mensaje.value = simular
      ? `Previsualización: ${data.vales} vales, ${euros(data.importeTotal)}. Caducan el ${data.fechaCaducidad}.`
      : `Emitidos ${data.vales} vales (${euros(data.importeTotal)}). Caducan el ${data.fechaCaducidad}.`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar los vales')
  } finally {
    cargando.value = false
  }
}

async function cambiarSinPuntos(codigo: string, sinPuntos: boolean) {
  const tienda = configuracion.value?.tiendas.find((t) => t.codigo === codigo)
  if (!tienda || tienda.sinPuntos === sinPuntos) return
  const anterior = tienda.sinPuntos
  tienda.sinPuntos = sinPuntos
  guardandoTienda.value = codigo
  error.value = ''
  mensaje.value = ''
  try {
    await marcarTiendaSinPuntos(codigo, sinPuntos)
    mensaje.value = sinPuntos
      ? `${tienda.nombre || codigo} no suma puntos de fidelización.`
      : `${tienda.nombre || codigo} vuelve a sumar puntos de fidelización.`
  } catch (e: unknown) {
    tienda.sinPuntos = anterior
    error.value = extractApiError(e, 'No se pudo guardar la tienda')
  } finally {
    guardandoTienda.value = ''
  }
}

async function guardarPuntos() {
  const modelo = modeloSeleccionado.value
  if (!modelo || modelo.motor !== 'PUNTOS') return
  guardandoPuntos.value = true
  error.value = ''
  mensaje.value = ''
  try {
    const guardado = await guardarPuntosFidelizacion({
      codigo: modelo.codigo,
      porcentaje: Number(puntos.value.porcentaje),
      importeMinimo: Number(puntos.value.importeMinimo) || 0,
      exclusiones: puntos.value.exclusiones,
      multiplo: Number(puntos.value.multiplo),
      valorPunto: Number(puntos.value.valorPunto),
    })
    modelo.configuracion = { ...modelo.configuracion, ...guardado }
    modelo.factor = guardado.porcentaje / 100
    editandoPuntos.value = false
    rellenarPuntos()
    mensaje.value = 'Configuración de puntos guardada.'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar la configuración de puntos')
  } finally {
    guardandoPuntos.value = false
  }
}

async function seleccionar(codigo: string) {
  if (!empresa.value || codigo === configuracion.value?.seleccionado) return
  seleccionando.value = true
  error.value = ''
  mensaje.value = ''
  try {
    configuracion.value = await seleccionarModeloFidelizacion(empresa.value, codigo)
    pjeCanje.value = Number(modeloSeleccionado.value?.configuracion.pjeCanje ?? 3)
    mensaje.value = `Modelo «${modeloSeleccionado.value?.nombre ?? codigo}» seleccionado para esta tienda.`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo seleccionar el modelo')
  } finally {
    seleccionando.value = false
  }
}
</script>

<template>
  <section class="fid">
    <RouterLink to="/configuracion" class="volver">← Configuración</RouterLink>
    <h2>Fidelización</h2>
    <p class="intro">
    Seleccione un único modelo para la tienda {{ empresa }}. El círculo marcado es el programa que
    se aplicará en las ventas. Los puntos suman las compras de las tiendas que sí hacen fidelización.
    </p>

    <div class="tabla-wrap modelos">
      <table>
        <thead>
          <tr>
            <th class="seleccion">Usar</th>
            <th>Modelo</th>
            <th>Funcionamiento</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="modelo in configuracion?.modelos ?? []" :key="modelo.codigo">
            <td class="seleccion">
              <input
                type="radio"
                name="modelo-fidelizacion"
                :value="modelo.codigo"
                :checked="configuracion?.seleccionado === modelo.codigo"
                :disabled="seleccionando || !puede('clientes', 'editar')"
                @change="seleccionar(modelo.codigo)"
              />
            </td>
            <td>
              <strong>{{ modelo.nombre }}</strong>
              <small>{{ modelo.codigo }}</small>
            </td>
            <td>
              <template v-if="modelo.motor === 'VALE_SEMESTRAL'">
                1 € = {{ modelo.factor }} punto. Vale del
                {{ modelo.configuracion.pjeCanje ?? 3 }} % cada semestre.
              </template>
              <template v-else-if="modelo.motor === 'PUNTOS'">
                Acumula {{ modelo.factor }} punto por euro.
              </template>
              <template v-else-if="modelo.motor === 'EUROS'">
                Acumula saldo en euros según el porcentaje del cliente.
              </template>
              <template v-else>Sin acumulación.</template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="tabla-wrap modelos">
      <table>
        <thead>
          <tr>
            <th>Tienda</th>
            <th>Nombre</th>
            <th class="sin-puntos">No hace puntos</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tienda in configuracion?.tiendas ?? []" :key="tienda.codigo">
            <td>{{ tienda.codigo }}</td>
            <td>{{ tienda.nombre }}</td>
            <td class="sin-puntos">
              <input
                type="checkbox"
                :checked="tienda.sinPuntos"
                :disabled="guardandoTienda !== '' || !puede('clientes', 'editar')"
                :title="`Esta tienda no hace puntos de fidelización`"
                @change="cambiarSinPuntos(tienda.codigo, ($event.target as HTMLInputElement).checked)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="intro">
      Marcada, esa tienda no acumula puntos ni entra en el vale del semestre. El modelo se conserva.
    </p>

    <div v-if="esPuntos" class="ficha-panel">
      <div class="toolbar">
        <span class="toolbar-titulo">Puntos (1 / €)</span>
        <div class="toolbar-spacer"></div>
        <button
          v-if="puedeEditarPuntos"
          type="button"
          class="tool-btn"
          :disabled="editandoPuntos"
          @click="modificarPuntos"
        >
          Modificar
        </button>
        <button
          v-if="puedeEditarPuntos && editandoPuntos"
          type="button"
          class="tool-btn primary"
          :disabled="guardandoPuntos"
          @click="guardarPuntos"
        >
          {{ guardandoPuntos ? 'Guardando…' : 'Guardar' }}
        </button>
        <button v-if="editandoPuntos" type="button" class="tool-btn" @click="cancelarPuntos">
          Cancelar
        </button>
      </div>

      <div class="ficha">
        <div class="bloques">
          <div class="col-izquierda">
            <fieldset class="bloque">
              <legend>Cálculo</legend>
              <div class="fila-campo">
                <span>% sobre la venta</span>
                <DecimalInput
                  v-model="puntos.porcentaje"
                  class="input-num"
                  :empty-as-null="false"
                  :readonly="soloLecturaPuntos"
                />
              </div>
              <div class="fila-campo">
                <span>Importe mínimo venta</span>
                <DecimalInput
                  v-model="puntos.importeMinimo"
                  class="input-num"
                  :empty-as-null="false"
                  :readonly="soloLecturaPuntos"
                />
              </div>
            </fieldset>

            <fieldset class="bloque">
              <legend>Canje</legend>
              <div class="fila-campo">
                <span>Múltiplo de canje</span>
                <DecimalInput
                  v-model="puntos.multiplo"
                  class="input-num"
                  :empty-as-null="false"
                  :integer="true"
                  :readonly="soloLecturaPuntos"
                />
              </div>
              <div class="fila-campo">
                <span>Valor de cada punto (€)</span>
                <DecimalInput
                  v-model="puntos.valorPunto"
                  class="input-num"
                  :empty-as-null="false"
                  :readonly="soloLecturaPuntos"
                />
              </div>
            </fieldset>

            <fieldset class="bloque">
              <legend>Resumen</legend>
              <p class="nota">
                Una venta de 100 € genera
                {{ Math.floor((100 * (Number(puntos.porcentaje) || 0)) / 100) }} puntos. Cada punto vale
                {{ euros(Number(puntos.valorPunto) || 0) }} y se gastan en la venta siguiente, de
                {{ puntos.multiplo || 1 }} en {{ puntos.multiplo || 1 }}.
              </p>
            </fieldset>
          </div>

          <div class="col-derecha">
            <fieldset class="bloque">
              <legend>Artículos excluidos</legend>
              <p class="nota">
                Todo suma puntos salvo lo marcado. Marcar un nivel excluye todo lo que cuelga de él.
              </p>
              <FidelizacionArbolExclusion
                v-model="puntos.exclusiones"
                :readonly="soloLecturaPuntos"
              />
            </fieldset>
          </div>
        </div>
      </div>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>

    <div v-if="esSemestral" class="ficha-panel">
      <div class="toolbar">
        <span class="toolbar-titulo">Vale semestral (1 punto por euro)</span>
        <div class="toolbar-spacer"></div>
        <button
          v-if="puedeEditarPuntos"
          type="button"
          class="tool-btn"
          :disabled="editandoSemestre"
          @click="modificarSemestre"
        >
          Modificar
        </button>
        <button
          v-if="puedeEditarPuntos && editandoSemestre"
          type="button"
          class="tool-btn primary"
          :disabled="cargando"
          @click="guardarSemestre"
        >
          {{ cargando ? 'Guardando…' : 'Guardar' }}
        </button>
        <button v-if="editandoSemestre" type="button" class="tool-btn" @click="cancelarSemestre">
          Cancelar
        </button>
        <button type="button" class="tool-btn" :disabled="cargando" @click="lanzar(true)">
          {{ cargando ? 'Calculando…' : 'Previsualizar' }}
        </button>
        <button
          type="button"
          class="tool-btn primary"
          :disabled="cargando || !puede('ventas-vales', 'crear')"
          @click="lanzar(false)"
        >
          Emitir vales
        </button>
      </div>

      <div class="ficha">
        <div class="bloques">
          <div class="col-izquierda">
            <fieldset class="bloque">
              <legend>Canje</legend>
              <div class="fila-campo">
                <span>% del vale</span>
                <DecimalInput
                  v-model="pjeCanje"
                  class="input-num"
                  :empty-as-null="false"
                  :readonly="!editandoSemestre"
                />
              </div>
              <p class="nota">1 € de compra = 1 punto. El vale es ese porcentaje del semestre.</p>
            </fieldset>

            <fieldset class="bloque">
              <legend>Generar vales</legend>
              <p class="nota">
                Se generan solos, una vez, al abrir el programa entre el
                {{ estadoAutomatico?.ventanaInicio ?? '1 de enero / 1 de julio' }} y el
                {{ estadoAutomatico?.ventanaFin ?? 'final de ese mes' }}.
                <template v-if="estadoAutomatico?.yaGenerado">
                  El periodo {{ estadoAutomatico.fechaInicio }} a {{ estadoAutomatico.fechaFin }} ya está liquidado.
                </template>
              </p>
              <div class="fila-campo">
                <span>Empresa</span>
                <input v-model="empresa" class="input-corto" maxlength="4" />
              </div>
              <div class="fila-campo">
                <span>Desde</span>
                <input v-model="fechaInicio" class="input-fecha" type="date" />
              </div>
              <div class="fila-campo">
                <span>Hasta</span>
                <input v-model="fechaFin" class="input-fecha" type="date" />
              </div>
              <label class="check">
                <input v-model="forzar" type="checkbox" />
                Forzar si este periodo ya se generó
              </label>
            </fieldset>
          </div>

          <div class="col-derecha">
            <fieldset class="bloque">
              <legend>Artículos excluidos</legend>
              <p class="nota">
                Todo suma puntos salvo lo marcado. Marcar un nivel excluye todo lo que cuelga de él.
              </p>
              <FidelizacionArbolExclusion
                v-model="exclusionesSemestre"
                :readonly="!editandoSemestre"
              />
            </fieldset>
          </div>
        </div>
      </div>
    </div>

    <div v-if="resultado?.clientes.length" class="tabla-wrap">
      <table>
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Razón social</th>
            <th>Tarjeta</th>
            <th class="n">Compras</th>
            <th class="n">Puntos</th>
            <th class="n">Vale</th>
            <th v-if="!resultado.simulado" class="n">Nº vale</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in resultado.clientes" :key="c.cliente">
            <td>{{ c.cliente }}</td>
            <td>{{ c.razonSocial }}</td>
            <td>{{ c.tarjetaFidelizacion }}</td>
            <td class="n">{{ euros(c.importeTotal) }}</td>
            <td class="n">{{ c.puntos.toLocaleString('es-ES') }}</td>
            <td class="n">{{ euros(c.importeCanje) }}</td>
            <td v-if="!resultado.simulado" class="n">{{ c.vale ?? '' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

  </section>
</template>

<style scoped>
.fid { max-width: 64rem; }
.volver { color: #2563eb; text-decoration: none; font-size: .85rem; }
h2 { margin: .55rem 0 .25rem; }
h3 { margin: 0 0 .75rem; font-size: 1rem; }
.intro { color: #64748b; font-size: .9rem; }
.panel { margin-top: 1rem; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; }
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
label { display: flex; flex-direction: column; gap: .3rem; font-size: .85rem; }
label span { font-weight: 600; }
input, select { padding: .45rem .55rem; border: 1px solid #cbd5e1; border-radius: 7px; }
.ancho { grid-column: 1 / -1; }
td small { display: block; margin-top: .15rem; color: #64748b; }
.check { flex-direction: row; align-items: center; gap: .5rem; margin: .85rem 0; }
.error { color: #b91c1c; }
.ok { color: #047857; }
.acciones { display: flex; justify-content: flex-end; gap: .5rem; }
button { padding: .45rem .85rem; border: 1px solid #94a3b8; border-radius: 7px; background: #fff; cursor: pointer; }
button.principal { background: #2563eb; border-color: #1d4ed8; color: #fff; }
button:disabled { opacity: .5; cursor: not-allowed; }
.tabla-wrap { margin-top: 1rem; overflow: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
.modelos { margin-bottom: .75rem; background: #fff; }
table { width: 100%; border-collapse: collapse; font-size: .82rem; }
th, td { padding: .4rem .55rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
.seleccion { width: 4rem; text-align: center; }
.sin-puntos { width: 8.5rem; text-align: center; }
.n { text-align: right; font-variant-numeric: tabular-nums; }
@media (max-width: 700px) { .grid { grid-template-columns: 1fr; } .ancho { grid-column: auto; } }

.ficha-panel { margin-top: 1rem; }
.toolbar {
  display: flex;
  align-items: center;
  gap: .35rem;
  padding: .5rem;
  background: linear-gradient(180deg, #f8fafc 0%, #e5e7eb 100%);
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  margin-bottom: .5rem;
}
.toolbar-titulo { font-weight: 600; font-size: .9rem; padding-left: .25rem; }
.toolbar-spacer { flex: 1; }
.tool-btn {
  padding: .35rem .75rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: .8rem;
  cursor: pointer;
}
.tool-btn.primary { background: #2563eb; border-color: #1d4ed8; color: #fff; }
.tool-btn:disabled { opacity: .45; cursor: not-allowed; }
.ficha {
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  padding: .65rem;
}
.bloques { display: flex; flex-wrap: wrap; align-items: flex-start; gap: .65rem; }
.col-izquierda { display: flex; flex-direction: column; gap: .65rem; width: 17rem; }
.col-derecha { display: flex; flex-direction: column; gap: .65rem; flex: 1; min-width: 22rem; }
.bloque {
  margin: 0;
  padding: .45rem .55rem .55rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
  display: grid;
  gap: .35rem;
}
.bloque legend { padding: 0 .35rem; font-size: .75rem; font-weight: 600; }
.fila-campo {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: .4rem;
  font-size: .78rem;
}
.ficha .input-num {
  width: 6rem;
  max-width: 6rem;
  padding: .25rem .35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: .8rem;
  text-align: right;
  box-sizing: border-box;
}
.ficha .input-num:read-only { background: #f1f5f9; }
.ficha .input-corto,
.ficha .input-fecha {
  padding: .25rem .35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: .8rem;
  box-sizing: border-box;
}
.ficha .input-corto { width: 4.5rem; }
.ficha .input-fecha { width: 9.5rem; }
.ficha .nota { margin: 0; font-size: .78rem; color: #64748b; }
@media (max-width: 900px) {
  .bloques { flex-direction: column; }
  .col-izquierda, .col-derecha { width: 100%; min-width: 0; }
}
</style>
