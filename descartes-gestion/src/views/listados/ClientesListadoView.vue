<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { generarClientesListado, type ClientesListadoResult } from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useAuthStore } from '@/stores/auth'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'
import { useGridRenderLimit } from '@/composables/useGridRenderLimit'

type Opt = { value: string; label: string }

const auth = useAuthStore()
const { registrarReciente } = useListadosRecientes()

const generando = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<ClientesListadoResult | null>(null)
const tiendas = ref<Opt[]>([])

function formularioInicial() {
  return {
    texto: '',
    estado: 'activos',
    fidelizacion: 'todos',
    empresa: '',
    nombre: '',
    nif: '',
    telefono: '',
    email: '',
    poblacion: '',
    provincia: '',
    codigoPostal: '',
    pais: '',
    vendedor: '',
    actividad: '',
    formaPago: '',
    tipologia: '',
    altaDesde: '',
    altaHasta: '',
    ultimaCompraDesde: '',
    ultimaCompraHasta: '',
    sinCompraDesde: '',
    tarjeta: '',
    puntosMin: '',
    fidelAltaDesde: '',
    fidelAltaHasta: '',
    propaganda: 'todos',
    conEmail: 'todos',
    conTelefono: 'todos',
    orden: 'nombre',
  }
}

const form = ref(formularioInicial())

const filasListado = computed(() => resultado.value?.items ?? [])
const tieneDatos = computed(() => filasListado.value.length > 0)
const { gridEl, visibles, onScrollGrid } = useGridRenderLimit(filasListado)

const ETIQUETAS: Record<string, Record<string, string>> = {
  estado: { activos: 'Activos', baja: 'De baja', todos: 'Todos' },
  fidelizacion: { todos: 'Todos', con: 'Con fidelización', sin: 'Sin fidelización' },
  siNo: { todos: 'Todos', si: 'Sí', no: 'No' },
}

function filtrosActivos(): [string, string][] {
  const f = form.value
  const lineas: [string, string][] = [
    ['Estado', ETIQUETAS.estado[f.estado] ?? f.estado],
    ['Fidelización', ETIQUETAS.fidelizacion[f.fidelizacion] ?? f.fidelizacion],
  ]
  const textos: [string, string][] = [
    ['Búsqueda', f.texto],
    ['Tienda', f.empresa],
    ['Nombre', f.nombre],
    ['NIF', f.nif],
    ['Teléfono', f.telefono],
    ['Email', f.email],
    ['Población', f.poblacion],
    ['Provincia', f.provincia],
    ['C. postal', f.codigoPostal],
    ['País', f.pais],
    ['Vendedor', f.vendedor],
    ['Actividad', f.actividad],
    ['Forma de pago', f.formaPago],
    ['Tipología', f.tipologia],
    ['Alta desde', f.altaDesde],
    ['Alta hasta', f.altaHasta],
    ['Última compra desde', f.ultimaCompraDesde],
    ['Última compra hasta', f.ultimaCompraHasta],
    ['Sin comprar desde', f.sinCompraDesde],
    ['Tarjeta', f.tarjeta],
    ['Puntos mínimos', f.puntosMin],
    ['Alta fidelización desde', f.fidelAltaDesde],
    ['Alta fidelización hasta', f.fidelAltaHasta],
  ]
  for (const [k, v] of textos) if (v.trim()) lineas.push([k, v.trim()])
  const siNo: [string, string][] = [
    ['Acepta publicidad', f.propaganda],
    ['Con email', f.conEmail],
    ['Con teléfono', f.conTelefono],
  ]
  for (const [k, v] of siNo) if (v !== 'todos') lineas.push([k, ETIQUETAS.siNo[v] ?? v])
  return lineas
}

function metaImpresion(): string[] {
  const lines = [filtrosActivos().map(([k, v]) => `${k}: ${v}`).join(' · ')]
  const u = auth.usuario?.nombre
  if (u) lines.push(`Usuario: ${u}`)
  lines.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lines
}

function fechaEs(iso: string): string {
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : iso
}

async function cargarTiendas() {
  loadingOpts.value = true
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre?: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: `${codigo}${t.nombre ? ` — ${t.nombre}` : ''}` }
    })
  } catch {
    /* ignore */
  } finally {
    loadingOpts.value = false
  }
}

function limpiar() {
  form.value = formularioInicial()
  resultado.value = null
  mensaje.value = null
  error.value = null
}

let seq = 0
async function generar() {
  const f = form.value
  for (const [a, b] of [
    [f.altaDesde, f.altaHasta],
    [f.ultimaCompraDesde, f.ultimaCompraHasta],
    [f.fidelAltaDesde, f.fidelAltaHasta],
  ]) {
    if (a && b && a > b) {
      error.value = 'Una fecha desde no puede ser posterior a su fecha hasta.'
      return
    }
  }

  const params: Record<string, string | undefined> = {}
  for (const [k, v] of Object.entries(f)) {
    const t = String(v ?? '').trim()
    if (t) params[k] = t
  }
  const id = ++seq
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await generarClientesListado(params)
    if (id !== seq) return
    resultado.value = data
    const t = data.totales
    if (!data.items.length) {
      mensaje.value = 'Ningún cliente cumple los filtros.'
    } else if (data.truncado) {
      mensaje.value = `Se muestran los primeros ${data.limite} clientes. Acote la búsqueda.`
    } else {
      mensaje.value = `${t.clientes} cliente(s), ${t.conFidelizacion} con fidelización.`
    }
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el informe de clientes')
    resultado.value = null
  } finally {
    if (id === seq) generando.value = false
  }
}

function exportarExcel() {
  const filas = filasListado.value
  if (!filas.length) return
  const cab = [
    'Código', 'Nombre', 'NIF', 'Teléfono', 'Email', 'Dirección', 'C. postal', 'Población', 'Provincia',
    'Tienda', 'Vendedor', 'Alta', 'Última compra', 'Baja', 'Acepta publicidad',
    'Tarjeta fidelización', 'Alta fidelización', 'Puntos', 'Acumulado fidelización',
  ]
  const lines = [cab.map(escCsv).join(';')]
  for (const r of filas) {
    lines.push(
      [
        r.codigo, r.nombre, r.nif, r.telefono, r.email, r.direccion, r.codigoPostal, r.poblacion,
        r.provincia, r.empresa, r.vendedor, fechaEs(r.fechaAlta), fechaEs(r.ultimaCompra),
        r.baja ? 'Sí' : 'No', r.propaganda ? 'Sí' : 'No', r.tarjeta, fechaEs(r.fidelAlta),
        numCsv(r.puntos), numCsv(r.acumuladoFidelizacion),
      ]
        .map(escCsv)
        .join(';')
    )
  }
  descargarCsv('informe-clientes.csv', lines)
  mensaje.value = 'Excel (CSV) exportado'
}

async function imprimir() {
  const filas = filasListado.value
  if (!filas.length) return
  const t = resultado.value!.totales
  const res = await imprimirListadoHtml({
    titulo: 'Informe de clientes',
    metaLineas: metaImpresion(),
    orientacion: 'horizontal',
    thead: ['Código', 'Nombre', 'NIF', 'Teléfono', 'Población', 'Últ. compra', 'Tarjeta', 'Puntos'],
    columnas: [
      { ancho: '8%', alineacion: 'left' },
      { ancho: '28%', alineacion: 'left' },
      { ancho: '10%', alineacion: 'left' },
      { ancho: '11%', alineacion: 'left' },
      { ancho: '18%', alineacion: 'left' },
      { ancho: '9%', alineacion: 'left' },
      { ancho: '10%', alineacion: 'left' },
      { ancho: '6%', alineacion: 'right' },
    ],
    filas: filas.map((r) => [
      r.codigo,
      r.baja ? `${r.nombre} (baja)` : r.nombre,
      r.nif,
      r.telefono,
      r.poblacion,
      fechaEs(r.ultimaCompra),
      r.tarjeta,
      r.fidelizacion ? numCsv(r.puntos) : '',
    ]),
    pie: [
      `Clientes: ${t.clientes}`,
      `Con fidelización: ${t.conFidelizacion}`,
      `Puntos acumulados: ${numCsv(t.puntos)}`,
    ],
    filenameFallback: 'informe-clientes.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('clientes')
  void cargarTiendas()
})
</script>

<template>
  <ListadoInformeLayout
    titulo="Informe de clientes"
    descripcion="Busque clientes por varios parámetros. «Con fidelización» significa que el cliente tiene número de tarjeta de fidelización."
    :generando="generando"
    :tiene-datos="tieneDatos"
    @generar="generar"
    @excel="exportarExcel"
    @imprimir="imprimir"
  >
    <template #filtros>
      <label class="campo-texto">
        <span>Buscar (código, nombre, NIF, teléfono, email, tarjeta…)</span>
        <input v-model="form.texto" type="text" @keyup.enter="generar" />
      </label>
      <label>
        <span>Fidelización</span>
        <select v-model="form.fidelizacion">
          <option value="todos">Todos</option>
          <option value="con">Con fidelización</option>
          <option value="sin">Sin fidelización</option>
        </select>
      </label>
      <label>
        <span>Estado</span>
        <select v-model="form.estado">
          <option value="activos">Activos</option>
          <option value="baja">De baja</option>
          <option value="todos">Todos</option>
        </select>
      </label>
      <label>
        <span>Tienda</span>
        <select v-model="form.empresa" :disabled="loadingOpts">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </label>
      <label>
        <span>Ordenar por</span>
        <select v-model="form.orden">
          <option value="nombre">Nombre</option>
          <option value="codigo">Código</option>
          <option value="poblacion">Población</option>
          <option value="ultimaCompra">Última compra</option>
          <option value="puntos">Puntos</option>
        </select>
      </label>
      <button type="button" class="btn-limpiar" @click="limpiar">Limpiar filtros</button>
    </template>

    <template #mas-filtros>
      <label>
        <span>Nombre / razón social</span>
        <input v-model="form.nombre" type="text" />
      </label>
      <label>
        <span>NIF</span>
        <input v-model="form.nif" type="text" />
      </label>
      <label>
        <span>Teléfono</span>
        <input v-model="form.telefono" type="text" />
      </label>
      <label>
        <span>Email</span>
        <input v-model="form.email" type="text" />
      </label>
      <label>
        <span>Población</span>
        <input v-model="form.poblacion" type="text" />
      </label>
      <label>
        <span>Provincia</span>
        <input v-model="form.provincia" type="text" />
      </label>
      <label>
        <span>C. postal (empieza por)</span>
        <input v-model="form.codigoPostal" type="text" />
      </label>
      <label>
        <span>País</span>
        <input v-model="form.pais" type="text" />
      </label>
      <label class="campo-lookup">
        <span>Vendedor</span>
        <EntidadLookupField v-model="form.vendedor" entidad="trabajadores" titulo-modal="Buscar vendedor" />
      </label>
      <label class="campo-lookup">
        <span>Actividad</span>
        <EntidadLookupField v-model="form.actividad" entidad="actividades" titulo-modal="Buscar actividad" />
      </label>
      <label class="campo-lookup">
        <span>Forma de pago</span>
        <EntidadLookupField v-model="form.formaPago" entidad="formas-pago" titulo-modal="Buscar forma de pago" />
      </label>
      <label>
        <span>Tipología</span>
        <input v-model="form.tipologia" type="text" />
      </label>
      <label>
        <span>Alta desde</span>
        <input v-model="form.altaDesde" type="date" />
      </label>
      <label>
        <span>Alta hasta</span>
        <input v-model="form.altaHasta" type="date" />
      </label>
      <label>
        <span>Última compra desde</span>
        <input v-model="form.ultimaCompraDesde" type="date" />
      </label>
      <label>
        <span>Última compra hasta</span>
        <input v-model="form.ultimaCompraHasta" type="date" />
      </label>
      <label>
        <span>Sin comprar desde</span>
        <input v-model="form.sinCompraDesde" type="date" />
      </label>
      <label>
        <span>Nº tarjeta fidelización</span>
        <input v-model="form.tarjeta" type="text" />
      </label>
      <label>
        <span>Puntos mínimos</span>
        <input v-model="form.puntosMin" type="text" inputmode="decimal" />
      </label>
      <label>
        <span>Alta fidelización desde</span>
        <input v-model="form.fidelAltaDesde" type="date" />
      </label>
      <label>
        <span>Alta fidelización hasta</span>
        <input v-model="form.fidelAltaHasta" type="date" />
      </label>
      <label>
        <span>Acepta publicidad</span>
        <select v-model="form.propaganda">
          <option value="todos">Todos</option>
          <option value="si">Sí</option>
          <option value="no">No</option>
        </select>
      </label>
      <label>
        <span>Con email</span>
        <select v-model="form.conEmail">
          <option value="todos">Todos</option>
          <option value="si">Sí</option>
          <option value="no">No</option>
        </select>
      </label>
      <label>
        <span>Con teléfono</span>
        <select v-model="form.conTelefono">
          <option value="todos">Todos</option>
          <option value="si">Sí</option>
          <option value="no">No</option>
        </select>
      </label>
    </template>

    <template #aviso>
      <p v-if="error" class="flash flash-error">{{ error }}</p>
      <p v-else-if="mensaje" class="flash flash-ok">{{ mensaje }}</p>
    </template>

    <div v-if="resultado && tieneDatos && !generando" class="resumen">
      <span><strong>Clientes:</strong> {{ resultado.totales.clientes }}</span>
      <span><strong>Con fidelización:</strong> {{ resultado.totales.conFidelizacion }}</span>
      <span><strong>Puntos:</strong> {{ numCsv(resultado.totales.puntos) }}</span>
    </div>

    <div v-if="tieneDatos" ref="gridEl" class="grid-wrap" @scroll.passive="onScrollGrid">
      <table class="grid">
        <thead>
          <tr>
            <th>Código</th>
            <th>Nombre</th>
            <th>NIF</th>
            <th>Teléfono</th>
            <th>Email</th>
            <th>Población</th>
            <th>Tienda</th>
            <th>Alta</th>
            <th>Últ. compra</th>
            <th>Tarjeta</th>
            <th>Alta fidel.</th>
            <th class="num">Puntos</th>
            <th class="num">Acum. fidel.</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in visibles" :key="r.codigo" :class="{ baja: r.baja }">
            <td>{{ r.codigo }}</td>
            <td>{{ r.nombre }}<span v-if="r.baja" class="tag-baja">baja</span></td>
            <td>{{ r.nif }}</td>
            <td>{{ r.telefono }}</td>
            <td>{{ r.email }}</td>
            <td>{{ r.poblacion }}</td>
            <td>{{ r.empresa }}</td>
            <td>{{ fechaEs(r.fechaAlta) }}</td>
            <td>{{ fechaEs(r.ultimaCompra) }}</td>
            <td>{{ r.tarjeta }}</td>
            <td>{{ r.fidelizacion ? fechaEs(r.fidelAlta) : '' }}</td>
            <td class="num">{{ r.fidelizacion ? numCsv(r.puntos) : '' }}</td>
            <td class="num">{{ r.fidelizacion ? numCsv(r.acumuladoFidelizacion) : '' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else-if="resultado && !generando" class="sin-datos">Ningún cliente cumple los filtros.</p>
  </ListadoInformeLayout>
</template>

<style scoped>
@import './listado-grid.css';

.campo-texto {
  min-width: 22rem;
}

.campo-texto input {
  width: 100%;
  box-sizing: border-box;
}

.campo-lookup {
  min-width: 14rem;
}

.btn-limpiar {
  padding: 0.35rem 0.75rem;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.85rem;
}

.resumen {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem 1.5rem;
  margin-bottom: 0.75rem;
  font-size: 0.9rem;
  color: #334155;
}

.grid tr.baja td {
  color: #94a3b8;
}

.tag-baja {
  margin-left: 0.4rem;
  padding: 0 0.35rem;
  border-radius: 4px;
  background: #fee2e2;
  color: #b91c1c;
  font-size: 0.72rem;
}
</style>
