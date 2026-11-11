<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'
import { imprimirEtiquetasEncargo } from '@/composables/useImpresionEtiquetaEncargo'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useVentasBusquedaStore } from '@/stores/ventasBusqueda'

/**
 * Seguimiento de encargos. El encargo nace en la venta (líneas con sobre);
 * aquí solo se consulta, se busca (también con el lector sobre la etiqueta),
 * se reimprimen etiquetas y se abre el albarán para cobrar/entregar.
 */
type Encargo = {
  numero: number
  cliente: string
  nombre: string
  telefono: string
  email: string
  albaranEmpresa: string
  albaranTipo: string
  albaran: number
  origenNombre: string
  destinoNombre: string
  modalidad: string
  etiquetasPedidas: number
  etiquetasImpresas: number
  tipo: string
  estado: string
  fechaAlta: string
  codigoBarras?: string
}

type FiltroEstado = 'todos' | 'pendientes' | 'entregados'

const router = useRouter()
const puesto = usePuestoContextoStore()
const busquedaVentas = useVentasBusquedaStore()
const encargos = ref<Encargo[]>([])
const filtroEncargo = ref('')
const filtroEstado = ref<FiltroEstado>('todos')
const error = ref<string | null>(null)
const aviso = ref<string | null>(null)
const cargando = ref(false)
const imprimiendo = ref<number | null>(null)
let filtroTimer: ReturnType<typeof setTimeout> | undefined
let listaSeq = 0

const estadoTxt: Record<string, string> = {
  pendiente_revelar: 'Pendiente de revelar',
  pendiente_entrega: 'Ya revelado, pendiente de entrega',
  entregado: 'Entregado',
}

function textoEstado(e: { estado: string; tipo?: string }): string {
  if (e.estado === 'pendiente_revelar' && e.tipo === 'impresion') return 'Pendiente de imprimir'
  if (e.estado === 'pendiente_entrega' && e.tipo === 'impresion') return 'Pendiente de entrega'
  return estadoTxt[e.estado] || e.estado
}

function fechaCorta(valor: unknown): string {
  const m = String(valor ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : String(valor ?? '')
}

const visibles = computed(() => {
  if (filtroEstado.value === 'pendientes') return encargos.value.filter((e) => e.estado !== 'entregado')
  if (filtroEstado.value === 'entregados') return encargos.value.filter((e) => e.estado === 'entregado')
  return encargos.value
})

onMounted(() => {
  void cargar()
})

function lecturaNormalizada(texto: string): string {
  let normal = texto.trim().replace(/Ñ/gi, ':')
  if (/^DCTB/i.test(normal)) normal = normal.replace(/[-'´`’‘?]/g, ':')
  return normal
}

/** Etiquetas antiguas con QR (DCTB:E:cliente:n / DCTB:C:cliente). */
function esQrAntiguo(texto: string): boolean {
  return /^DCTB/i.test(texto.trim())
}

function qrAntiguoCompleto(texto: string): boolean {
  const normal = lecturaNormalizada(texto)
  return /^DCTB:E:.+:\d+$/i.test(normal) || /^DCTB:C:.+$/i.test(normal)
}

async function cargar() {
  const texto = filtroEncargo.value.trim()
  const seq = ++listaSeq
  cargando.value = true
  error.value = null
  aviso.value = null
  try {
    if (esQrAntiguo(texto)) {
      if (!qrAntiguoCompleto(texto)) {
        encargos.value = []
        error.value = 'Lectura no reconocida'
        return
      }
      const { data } = await api.get('/api/encargos/qr', { params: { codigo: lecturaNormalizada(texto) } })
      if (seq !== listaSeq) return
      if (data?.tipo === 'encargo' && data.encargo) {
        encargos.value = [data.encargo as Encargo]
        return
      }
      if (data?.tipo === 'cliente' && data.cliente) {
        const res = await api.get('/api/encargos', { params: { cliente: String(data.cliente.codigo) } })
        if (seq !== listaSeq) return
        encargos.value = res.data.items ?? []
        if (!encargos.value.length) aviso.value = `Cliente ${data.cliente.codigo} ${data.cliente.nombre}: sin encargos.`
        return
      }
      encargos.value = []
      error.value = 'Esta lectura no tiene encargo.'
      return
    }
    const { data } = await api.get('/api/encargos', { params: texto ? { q: texto } : {} })
    if (seq !== listaSeq) return
    encargos.value = data.items ?? []
    if (texto && !encargos.value.length) aviso.value = 'No hay encargos con ese dato.'
  } catch (e: unknown) {
    if (seq !== listaSeq) return
    encargos.value = []
    error.value = extractApiError(e, 'No se pudieron cargar los encargos')
  } finally {
    if (seq === listaSeq) cargando.value = false
  }
}

watch(filtroEncargo, (texto) => {
  if (filtroTimer) clearTimeout(filtroTimer)
  // El lector escribe el código seguido de Intro; al teclear se espera un momento.
  if (esQrAntiguo(texto) && !qrAntiguoCompleto(texto)) return
  filtroTimer = setTimeout(() => {
    void cargar()
  }, 300)
})

function buscarAhora() {
  if (filtroTimer) clearTimeout(filtroTimer)
  void cargar()
}

async function imprimir(e: Encargo) {
  if (imprimiendo.value !== null) return
  error.value = null
  aviso.value = null
  imprimiendo.value = e.numero
  try {
    const res = await imprimirEtiquetasEncargo(
      e.numero,
      String(puesto.puestoCodigo ?? ''),
      String(puesto.empresaCodigo ?? e.albaranEmpresa)
    )
    aviso.value = `${res.etiquetas} etiqueta(s) enviadas${res.impresora ? ` a ${res.impresora}` : ''}.`
    await recargarLista()
  } catch (err: unknown) {
    error.value = extractApiError(err, 'No se pudieron imprimir las etiquetas')
  } finally {
    imprimiendo.value = null
  }
}

async function entregar(e: Encargo) {
  if (!window.confirm(`¿Marcar el encargo ${e.albaranEmpresa}${e.albaran} como entregado al cliente?`)) return
  error.value = null
  aviso.value = null
  try {
    await api.post(`/api/encargos/${e.numero}/entregar`)
    aviso.value = `Encargo ${e.albaranEmpresa}${e.albaran} entregado.`
    await recargarLista()
  } catch (err: unknown) {
    error.value = extractApiError(err, 'No se pudo marcar como entregado')
  }
}

/** Relee la lista sin borrar el mensaje de impresión. */
async function recargarLista() {
  const mensaje = aviso.value
  await cargar()
  if (mensaje && !error.value) aviso.value = mensaje
}

async function imprimirTransporte(e: Encargo) {
  error.value = null
  try {
    const { data } = await api.post(`/api/encargos/${e.numero}/etiqueta-transporte`)
    const direccion = [data.direccion, data.codigoPostal, data.poblacion, data.provincia].filter(Boolean).join(', ')
    const esc = (v: unknown) =>
      String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const cuerpo = `<section>
        <h1>Encargo ${esc(data.numero)}</h1>
        <p><strong>${esc(data.nombre)}</strong><br>${esc(direccion)}<br>${esc(data.telefono)}</p>
        <p>Tienda: ${esc(data.tienda)}</p>
      </section>`
    imprimirHtml(
      `Transporte ${esc(data.numero)}`,
      cuerpo,
      'body{font-family:Arial,sans-serif} section{width:90mm;margin:8mm auto} h1{font-size:18px}'
    )
  } catch (err: unknown) {
    error.value = extractApiError(err, 'No se pudo imprimir la etiqueta de transporte')
  }
}

function imprimirHtml(titulo: string, cuerpo: string, estilo: string) {
  const marco = document.createElement('iframe')
  marco.setAttribute('aria-hidden', 'true')
  marco.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
  document.body.appendChild(marco)
  const doc = marco.contentDocument
  const ventana = marco.contentWindow
  if (!doc || !ventana) {
    marco.remove()
    return
  }
  doc.open()
  doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>${titulo}</title><style>${estilo}</style></head><body>${cuerpo}</body></html>`)
  doc.close()
  const cerrar = () => marco.remove()
  ventana.addEventListener('afterprint', cerrar)
  window.setTimeout(() => {
    ventana.focus()
    ventana.print()
  }, 200)
  window.setTimeout(cerrar, 60000)
}

function abrirAlbaran(e: Encargo) {
  const empresa = String(e.albaranEmpresa ?? '').trim()
  const tipo = String(e.albaranTipo ?? '').trim()
  const albaran = Number(e.albaran)
  if (empresa && tipo && albaran > 0 && e.estado !== 'entregado') {
    busquedaVentas.marcarAbrirEnEdicion({ empresa, tipo, albaran })
  }
  void router.push({ name: 'ventas-detalle', params: { empresa, tipo, albaran: String(albaran) } })
}
</script>

<template>
  <section class="encargos">
    <header class="head">
      <div>
        <h2>Seguimiento de encargos</h2>
        <p class="hint">
          Los encargos nacen en la venta: un artículo «lleva sobre» crea el encargo y sus etiquetas.
          Aquí se buscan, se reimprimen las etiquetas y se marcan como entregados.
        </p>
      </div>
    </header>

    <p v-if="error" class="mensaje error">{{ error }}</p>
    <p v-if="aviso" class="mensaje ok">{{ aviso }}</p>

    <div class="filtros">
      <label class="campo buscar">
        <span>Buscar</span>
        <input
          v-model="filtroEncargo"
          placeholder="Pase el lector por la etiqueta o escriba albarán, cliente o teléfono"
          autofocus
          @keydown.enter.prevent="buscarAhora"
        />
      </label>
      <div class="campo">
        <span>Mostrar</span>
        <div class="segmentos" role="group" aria-label="Mostrar">
          <button
            v-for="op in ([['todos', 'Todos'], ['pendientes', 'Pendientes'], ['entregados', 'Entregados']] as const)"
            :key="op[0]"
            type="button"
            :class="{ activo: filtroEstado === op[0] }"
            @click="filtroEstado = op[0]"
          >
            {{ op[1] }}
          </button>
        </div>
      </div>
      <span class="contador">{{ visibles.length }} {{ visibles.length === 1 ? 'encargo' : 'encargos' }}</span>
    </div>

    <div class="tabla-wrap">
      <p v-if="cargando" class="vacio">Cargando…</p>
      <table v-else>
        <thead>
          <tr>
            <th>Código</th>
            <th>Fecha</th>
            <th class="num">Sobres</th>
            <th>Cliente</th>
            <th>Trabajo</th>
            <th>Situación</th>
            <th class="col-acc">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in visibles" :key="e.numero">
            <td>
              <span class="codigo">{{ e.codigoBarras || `${e.albaranEmpresa}${e.albaran}` }}</span>
              <small>{{ e.albaranEmpresa }}/{{ e.albaranTipo }}/{{ e.albaran }}</small>
            </td>
            <td>{{ fechaCorta(e.fechaAlta) }}</td>
            <td class="num">{{ e.tipo === 'impresion' ? '—' : e.etiquetasPedidas }}</td>
            <td>
              <span class="nombre">{{ e.nombre }}</span>
              <small>{{ e.telefono }}</small>
            </td>
            <td>
              <span>{{ e.tipo === 'impresion' ? 'Impresión' : 'Revelado' }} · {{ e.modalidad === 'envio' ? 'Mensajero' : 'Recogida' }}</span>
              <small>{{ e.origenNombre }} → {{ e.destinoNombre }}</small>
            </td>
            <td>
              <span class="estado" :class="`estado-${e.estado}`">{{ textoEstado(e) }}</span>
            </td>
            <td class="col-acc">
              <div class="acc">
                <button type="button" class="btn btn-secundario" @click="abrirAlbaran(e)">Albarán</button>
                <button
                  v-if="e.tipo !== 'impresion' && e.estado !== 'entregado'"
                  type="button"
                  class="btn btn-principal"
                  :disabled="imprimiendo !== null"
                  @click="imprimir(e)"
                >
                  {{ imprimiendo === e.numero ? 'Imprimiendo…' : 'Etiquetas' }}
                </button>
                <button
                  v-if="e.estado !== 'entregado'"
                  type="button"
                  class="btn btn-exito"
                  :disabled="imprimiendo !== null"
                  @click="entregar(e)"
                >
                  Entregar
                </button>
                <button
                  v-if="e.modalidad === 'envio' && e.estado === 'pendiente_entrega'"
                  type="button"
                  class="btn btn-secundario"
                  @click="imprimirTransporte(e)"
                >
                  Transporte
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!visibles.length">
            <td colspan="7" class="vacio">No hay encargos que mostrar.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.encargos {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding: 1rem 1.25rem;
  color: #1e293b;
}
.head h2 { margin: 0 0 0.3rem; }
.hint { margin: 0; color: #64748b; font-size: 0.85rem; }
.mensaje {
  margin: 0;
  padding: 0.4rem 0.7rem;
  border-radius: 6px;
  font-size: 0.88rem;
}
.mensaje.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.mensaje.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.filtros {
  display: flex;
  align-items: flex-end;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 0.7rem 0.85rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
}
.campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #475569;
}
.campo.buscar { flex: 1; min-width: 18rem; max-width: 36rem; }
.campo input {
  padding: 0.45rem 0.6rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 0.9rem;
  font-weight: 400;
}
.campo input:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgb(37 99 235 / 15%);
}
.segmentos {
  display: inline-flex;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  overflow: hidden;
}
.segmentos button {
  padding: 0.42rem 0.8rem;
  border: 0;
  border-right: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}
.segmentos button:last-child { border-right: 0; }
.segmentos button.activo { background: #2563eb; color: #fff; font-weight: 600; }
.contador { margin-left: auto; color: #64748b; font-size: 0.85rem; }
.tabla-wrap {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
  overflow: auto;
}
table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
th {
  position: sticky;
  top: 0;
  background: #f1f5f9;
  color: #334155;
  font-weight: 600;
  text-align: left;
  padding: 0.5rem 0.65rem;
  border-bottom: 1px solid #e2e8f0;
  white-space: nowrap;
}
td {
  padding: 0.5rem 0.65rem;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}
tbody tr:hover td { background: #f8fafc; }
td small { display: block; color: #64748b; font-size: 0.78rem; }
.num { text-align: center; }
.codigo { font-weight: 700; color: #1e40af; }
.nombre { font-weight: 600; }
.estado {
  display: inline-block;
  padding: 0.12rem 0.55rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 600;
  white-space: nowrap;
  background: #fef3c7;
  color: #92400e;
}
.estado-pendiente_entrega { background: #dbeafe; color: #1e40af; }
.estado-entregado { background: #dcfce7; color: #166534; }
.col-acc { text-align: right; }
.acc { display: flex; justify-content: flex-end; gap: 0.35rem; flex-wrap: nowrap; }
.btn {
  padding: 0.32rem 0.7rem;
  border-radius: 6px;
  border: 1px solid transparent;
  font: inherit;
  font-size: 0.82rem;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  transition: background 0.15s;
}
.btn:disabled { opacity: 0.6; cursor: default; }
.btn-principal { background: #2563eb; border-color: #1d4ed8; color: #fff; }
.btn-principal:hover:not(:disabled) { background: #1d4ed8; }
.btn-exito { background: #fff; border-color: #16a34a; color: #15803d; }
.btn-exito:hover:not(:disabled) { background: #f0fdf4; }
.btn-secundario { background: #fff; border-color: #cbd5e1; color: #334155; }
.btn-secundario:hover:not(:disabled) { background: #f1f5f9; }
.vacio { padding: 1rem; text-align: center; color: #64748b; }
</style>
