<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  estadoAutomaticoFidelizacion,
  generarValesFidelizacion,
  obtenerConfiguracionFidelizacion,
  seleccionarModeloFidelizacion,
  semestreFidelizacion,
  type FidelizacionConfiguracion,
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
const pjeCanje = ref(3)
const resultado = ref<FidelizacionValesSemestreResultado | null>(null)
const cargando = ref(false)
const error = ref('')
const mensaje = ref('')
const forzar = ref(false)
const configuracion = ref<FidelizacionConfiguracion | null>(null)
const estadoAutomatico = ref<FidelizacionEstadoAutomatico | null>(null)
const seleccionando = ref(false)

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
    estadoAutomatico.value = estado
    pjeCanje.value = Number(modeloSeleccionado.value?.configuracion.pjeCanje ?? 3)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar la configuración de fidelización')
  }
})

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
      se aplicará en las ventas. Los puntos del vale semestral suman las compras de todas las tiendas.
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

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>

    <form v-if="esSemestral" class="panel" @submit.prevent="lanzar(true)">
      <h3>Generar vales del semestre</h3>
      <p class="intro">
        Se generan solos, una única vez, en el primer puesto que abra el programa entre el
        {{ estadoAutomatico?.ventanaInicio ?? '1 de enero / 1 de julio' }} y el
        {{ estadoAutomatico?.ventanaFin ?? 'final de ese mes' }}. Fuera de esas fechas no se emite
        nada automáticamente; aquí puede previsualizarlos o lanzarlos a mano. El cálculo suma la
        facturación de todas las tiendas; la empresa indica qué tienda registra los vales.
      </p>
      <p v-if="estadoAutomatico?.yaGenerado" class="intro">
        El periodo {{ estadoAutomatico.fechaInicio }} a {{ estadoAutomatico.fechaFin }} ya está
        liquidado.
      </p>
      <div class="grid">
        <label>
          <span>Empresa</span>
          <input v-model="empresa" maxlength="4" />
        </label>
        <label>
          <span>Desde</span>
          <input v-model="fechaInicio" type="date" />
        </label>
        <label>
          <span>Hasta</span>
          <input v-model="fechaFin" type="date" />
        </label>
        <label>
          <span>% canje</span>
          <input v-model.number="pjeCanje" type="number" readonly />
        </label>
      </div>
      <label class="check">
        <input v-model="forzar" type="checkbox" />
        <span>Forzar si este periodo ya se generó</span>
      </label>
      <div class="acciones">
        <button type="submit" :disabled="cargando">
          {{ cargando ? 'Calculando…' : 'Previsualizar' }}
        </button>
        <button
          type="button"
          class="principal"
          :disabled="cargando || !puede('ventas-vales', 'crear')"
          @click="lanzar(false)"
        >
          Emitir vales
        </button>
      </div>
    </form>

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
.n { text-align: right; font-variant-numeric: tabular-nums; }
@media (max-width: 700px) { .grid { grid-template-columns: 1fr; } .ancho { grid-column: auto; } }
</style>
