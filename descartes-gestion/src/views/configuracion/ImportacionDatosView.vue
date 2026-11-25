<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  importarLote,
  obtenerDefinicionImportacion,
  type ImportacionContexto,
  type ImportacionDefinicion,
  type ImportacionEntidad,
  type ImportacionFila,
  type ImportacionHoja,
  type ImportacionMensaje,
} from '@/api/importacion'
import { extractApiError } from '@/composables/extractApiError'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { descargarCsv } from '@/utils/csvExcel'
import {
  descargarBlob,
  generarPlantillaXlsx,
  leerLibroXlsx,
  lineasInformeErrores,
  normalizarCodigo,
  type HojaLeida,
  type LecturaLibro,
} from '@/utils/importacionExcel'

/** Filas por petición: evita pasar del tiempo máximo de PHP en el hosting. */
const TAMANO_LOTE = 200
/** Errores que se muestran en pantalla (el informe descargable los trae todos). */
const MAX_ERRORES_VISIBLES = 300

const MODULO_POR_ENTIDAD: Record<ImportacionEntidad, string> = {
  macrofamilias: 'macrofamilias',
  familias: 'familias',
  subfamilias: 'subfamilias',
  proveedores: 'proveedores',
  articulos: 'articulos',
  clientes: 'clientes',
}

interface Resumen {
  hoja: string
  entidad: ImportacionEntidad
  total: number
  creadas: number
  omitidas: number
  errores: number
}

type Fase = 'inicio' | 'leido' | 'simulado' | 'importado'

const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()

const definicion = ref<ImportacionDefinicion | null>(null)
const cargando = ref(true)
const error = ref('')
const archivo = ref<File | null>(null)
const lectura = ref<LecturaLibro | null>(null)
const fase = ref<Fase>('inicio')
const trabajando = ref(false)
const progreso = ref('')
const resumenes = ref<Resumen[]>([])
const errores = ref<ImportacionMensaje[]>([])
const avisos = ref<ImportacionMensaje[]>([])
const descargandoPlantilla = ref(false)

onMounted(async () => {
  try {
    definicion.value = await obtenerDefinicionImportacion()
  } catch (e) {
    error.value = extractApiError(e, 'No se pudo cargar la definición de la importación')
  } finally {
    cargando.value = false
  }
})

const hojasConDatos = computed<HojaLeida[]>(() => lectura.value?.hojas.filter((h) => h.filas.length > 0) ?? [])
const totalFilas = computed(() => hojasConDatos.value.reduce((n, h) => n + h.filas.length, 0))
const hayErrores = computed(() => errores.value.length > 0)
const erroresVisibles = computed(() => errores.value.slice(0, MAX_ERRORES_VISIBLES))
const sinPermiso = computed(() =>
  hojasConDatos.value.filter((h) => !puede(MODULO_POR_ENTIDAD[h.entidad], 'crear')).map((h) => h.hoja)
)

function definicionDe(entidad: ImportacionEntidad): ImportacionHoja | undefined {
  return definicion.value?.hojas.find((h) => h.entidad === entidad)
}

function reiniciarResultados() {
  resumenes.value = []
  errores.value = []
  avisos.value = []
  progreso.value = ''
}

async function descargarPlantilla() {
  if (!definicion.value) return
  error.value = ''
  descargandoPlantilla.value = true
  try {
    const blob = await generarPlantillaXlsx(definicion.value)
    descargarBlob('plantilla-importacion-descartes.xlsx', blob)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo generar la plantilla'
  } finally {
    descargandoPlantilla.value = false
  }
}

async function alElegirArchivo(ev: Event) {
  const input = ev.target as HTMLInputElement
  const f = input.files?.[0] ?? null
  input.value = ''
  if (!f || !definicion.value) return
  error.value = ''
  reiniciarResultados()
  lectura.value = null
  archivo.value = f
  fase.value = 'inicio'
  trabajando.value = true
  progreso.value = 'Leyendo el archivo…'
  try {
    lectura.value = await leerLibroXlsx(f, definicion.value)
    fase.value = 'leido'
  } catch (e) {
    archivo.value = null
    error.value =
      'No se pudo leer el archivo. Compruebe que es un Excel .xlsx (no .xls ni .csv). ' +
      (e instanceof Error ? e.message : '')
  } finally {
    trabajando.value = false
    progreso.value = ''
  }
}

/** Códigos y campos únicos repetidos dentro del archivo: se rechazan las repeticiones. */
function separarRepetidas(
  hoja: HojaLeida,
  def: ImportacionHoja
): { enviar: ImportacionFila[]; rechazadas: ImportacionMensaje[] } {
  const enviar: ImportacionFila[] = []
  const rechazadas: ImportacionMensaje[] = []
  const codigos = new Map<string, number>()
  const unicos = new Map<string, Map<string, number>>()
  for (const campo of def.unicos) unicos.set(campo, new Map())

  for (const f of hoja.filas) {
    let rechazo: ImportacionMensaje | null = null

    const codigo = String(f.datos[def.claveCodigo] ?? '').trim()
    if (codigo !== '') {
      const k = normalizarCodigo(codigo)
      const previa = codigos.get(k)
      if (previa !== undefined) {
        rechazo = {
          hoja: def.hoja,
          fila: f.fila,
          campo: def.claveCodigo,
          mensaje: `Código repetido en el archivo (ya aparece en la fila ${previa})`,
        }
      }
    }
    if (!rechazo) {
      for (const campo of def.unicos) {
        const valor = String(f.datos[campo] ?? '').trim().toLowerCase()
        if (valor === '') continue
        const previa = unicos.get(campo)?.get(valor)
        if (previa !== undefined) {
          const cab = def.columnas.find((c) => c.clave === campo)?.cabecera ?? campo
          rechazo = {
            hoja: def.hoja,
            fila: f.fila,
            campo,
            mensaje: `${cab} repetido en el archivo (ya aparece en la fila ${previa})`,
          }
          break
        }
      }
    }

    if (rechazo) {
      rechazadas.push(rechazo)
      continue
    }
    if (codigo !== '') codigos.set(normalizarCodigo(codigo), f.fila)
    for (const campo of def.unicos) {
      const valor = String(f.datos[campo] ?? '').trim().toLowerCase()
      if (valor !== '') unicos.get(campo)?.set(valor, f.fila)
    }
    enviar.push(f)
  }

  return { enviar, rechazadas }
}

/** Códigos que crea el propio archivo: el simulacro los da por existentes para las hojas que dependen de ellos. */
function contextoDelArchivo(): ImportacionContexto {
  const filasDe = (entidad: ImportacionEntidad) =>
    lectura.value?.hojas.find((h) => h.entidad === entidad)?.filas ?? []
  const codigosDe = (entidad: ImportacionEntidad) =>
    filasDe(entidad)
      .map((f) => String(f.datos.codigo ?? '').trim())
      .filter((c) => c !== '')
  return {
    macrofamilias: codigosDe('macrofamilias'),
    familias: filasDe('familias')
      .map((f) => ({
        codigo: String(f.datos.codigo ?? '').trim(),
        macroFamiliaCodigo: String(f.datos.macroFamiliaCodigo ?? '').trim(),
      }))
      .filter((f) => f.codigo !== '' && f.macroFamiliaCodigo !== ''),
    subfamilias: codigosDe('subfamilias'),
    proveedores: codigosDe('proveedores'),
  }
}

async function ejecutar(simulacro: boolean) {
  if (!definicion.value || !lectura.value || trabajando.value) return
  error.value = ''
  reiniciarResultados()
  trabajando.value = true
  const contexto = contextoDelArchivo()
  const empresaCodigo = puestoContexto.empresaCodigo ?? ''

  try {
    for (const def of definicion.value.hojas) {
      const hoja = hojasConDatos.value.find((h) => h.entidad === def.entidad)
      if (!hoja) continue

      const resumen: Resumen = {
        hoja: def.hoja,
        entidad: def.entidad,
        total: hoja.filas.length,
        creadas: 0,
        omitidas: 0,
        errores: 0,
      }
      resumenes.value.push(resumen)

      if (!puede(MODULO_POR_ENTIDAD[def.entidad], 'crear')) {
        errores.value.push({
          hoja: def.hoja,
          fila: 0,
          campo: '',
          mensaje: `No tiene permiso para crear ${def.titulo.toLowerCase()}: la hoja no se procesa`,
        })
        resumen.errores = hoja.filas.length
        continue
      }

      const { enviar, rechazadas } = separarRepetidas(hoja, def)
      errores.value.push(...rechazadas)
      resumen.errores += rechazadas.length

      for (let i = 0; i < enviar.length; i += TAMANO_LOTE) {
        const lote = enviar.slice(i, i + TAMANO_LOTE)
        progreso.value = `${simulacro ? 'Simulando' : 'Importando'} ${def.hoja}: ${Math.min(i + lote.length, enviar.length)} de ${enviar.length}`
        const r = await importarLote(def.entidad, lote, {
          simulacro,
          contexto,
          empresaCodigo,
        })
        resumen.creadas += r.creadas
        resumen.omitidas += r.omitidas
        resumen.errores += r.errores.length
        errores.value.push(...r.errores)
        avisos.value.push(...r.avisos)
      }
    }
    fase.value = simulacro ? 'simulado' : 'importado'
  } catch (e) {
    error.value = extractApiError(e, 'Se interrumpió el proceso')
  } finally {
    trabajando.value = false
    progreso.value = ''
  }
}

function descargarInforme() {
  descargarCsv('informe-importacion.csv', lineasInformeErrores(errores.value, avisos.value))
}

function vistaPrevia(h: HojaLeida): ImportacionFila[] {
  return h.filas.slice(0, 5)
}

function columnasDe(entidad: ImportacionEntidad) {
  return definicionDe(entidad)?.columnas ?? []
}

const etiquetaImportar = computed(() =>
  hayErrores.value ? 'Importar solo las filas correctas' : 'Importar'
)
</script>

<template>
  <section class="importacion">
    <RouterLink to="/configuracion" class="volver">← Configuración</RouterLink>
    <h2>Importación de datos</h2>
    <p class="intro">
      Cargue macrofamilias, familias, subfamilias, proveedores, artículos y clientes desde un Excel. Primero se hace una simulación
      (no guarda nada) y después la importación. Lo que ya existe no se modifica.
    </p>

    <p v-if="cargando">Cargando…</p>
    <template v-else-if="definicion">
      <div class="panel">
        <h3>1. Descargar plantilla</h3>
        <p class="nota">
          Una hoja por tipo de dato y una hoja «Instrucciones». Las columnas con * son obligatorias.
        </p>
        <button type="button" class="principal" :disabled="descargandoPlantilla" @click="descargarPlantilla">
          {{ descargandoPlantilla ? 'Generando…' : 'Descargar plantilla (.xlsx)' }}
        </button>
      </div>

      <div class="panel">
        <h3>2. Subir el Excel rellenado</h3>
        <label class="archivo">
          <input type="file" accept=".xlsx" :disabled="trabajando" @change="alElegirArchivo" />
        </label>
        <p v-if="archivo" class="nota">Archivo: {{ archivo.name }}</p>

        <ul v-if="lectura && lectura.avisos.length > 0" class="avisos">
          <li v-for="(a, i) in lectura.avisos" :key="i">{{ a }}</li>
        </ul>

        <template v-if="lectura">
          <p v-if="hojasConDatos.length === 0" class="aviso">
            El archivo no tiene filas para importar.
          </p>
          <template v-else>
            <p class="ok">{{ totalFilas }} filas leídas en {{ hojasConDatos.length }} hoja(s).</p>
            <p v-if="sinPermiso.length > 0" class="aviso">
              Sin permiso para crear en: {{ sinPermiso.join(', ') }}. Esas hojas no se procesarán.
            </p>
            <details v-for="h in hojasConDatos" :key="h.entidad" class="previa">
              <summary>{{ h.hoja }} — {{ h.filas.length }} filas (vista previa de las 5 primeras)</summary>
              <div class="tabla-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Fila</th>
                      <th v-for="c in columnasDe(h.entidad)" :key="c.clave">{{ c.cabecera }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="f in vistaPrevia(h)" :key="f.fila">
                      <td>{{ f.fila }}</td>
                      <td v-for="c in columnasDe(h.entidad)" :key="c.clave">{{ f.datos[c.clave] ?? '' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </details>
          </template>
        </template>
      </div>

      <div v-if="hojasConDatos.length > 0" class="panel">
        <h3>3. Simular e importar</h3>
        <div class="fila">
          <button type="button" :disabled="trabajando" @click="ejecutar(true)">Simular (no guarda)</button>
          <button
            type="button"
            class="principal"
            :disabled="trabajando || fase !== 'simulado'"
            :title="fase !== 'simulado' ? 'Primero haga la simulación' : ''"
            @click="ejecutar(false)"
          >
            {{ etiquetaImportar }}
          </button>
        </div>
        <p v-if="progreso" class="nota">{{ progreso }}</p>
        <p v-if="fase === 'simulado' && hayErrores" class="aviso">
          La simulación ha encontrado errores. Puede corregir el Excel y volver a subirlo, o importar solo
          las filas correctas.
        </p>
        <p v-if="fase === 'simulado' && !hayErrores" class="ok">
          Simulación correcta: no se ha guardado nada. Ya puede importar.
        </p>
        <p v-if="fase === 'importado'" class="ok">Importación terminada.</p>
      </div>

      <div v-if="resumenes.length > 0" class="panel">
        <h3>{{ fase === 'importado' ? 'Resultado de la importación' : 'Resultado de la simulación' }}</h3>
        <table class="resumen">
          <thead>
            <tr>
              <th>Hoja</th>
              <th>Filas</th>
              <th>{{ fase === 'importado' ? 'Creadas' : 'Se crearían' }}</th>
              <th>Omitidas (ya existen)</th>
              <th>Con error</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in resumenes" :key="r.entidad">
              <td>{{ r.hoja }}</td>
              <td>{{ r.total }}</td>
              <td>{{ r.creadas }}</td>
              <td>{{ r.omitidas }}</td>
              <td :class="{ malo: r.errores > 0 }">{{ r.errores }}</td>
            </tr>
          </tbody>
        </table>

        <template v-if="errores.length > 0 || avisos.length > 0">
          <div class="fila informe">
            <button type="button" @click="descargarInforme">Descargar informe (CSV)</button>
          </div>
          <div v-if="errores.length > 0" class="tabla-wrap">
            <table class="errores">
              <thead>
                <tr>
                  <th>Hoja</th>
                  <th>Fila</th>
                  <th>Campo</th>
                  <th>Error</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(e, i) in erroresVisibles" :key="i">
                  <td>{{ e.hoja }}</td>
                  <td>{{ e.fila || '—' }}</td>
                  <td>{{ e.campo }}</td>
                  <td>{{ e.mensaje }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="errores.length > MAX_ERRORES_VISIBLES" class="nota">
            Se muestran los primeros {{ MAX_ERRORES_VISIBLES }} de {{ errores.length }} errores. Descargue el
            informe para verlos todos.
          </p>
        </template>
      </div>
    </template>

    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>

<style scoped>
.importacion { max-width: 64rem; }
.volver { color: #2563eb; text-decoration: none; font-size: .85rem; }
h2 { margin: .6rem 0 .25rem; }
h3 { margin: 0 0 .7rem; font-size: .95rem; color: #0f172a; }
.intro { margin: 0 0 1rem; color: #64748b; }
.panel { margin-bottom: 1rem; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; }
.fila { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.informe { margin: .8rem 0 .5rem; }
button { padding: .45rem .8rem; border: 1px solid #94a3b8; border-radius: 7px; background: #fff; cursor: pointer; }
button.principal { color: #fff; border-color: #1d4ed8; background: #2563eb; }
button:disabled { opacity: .5; cursor: not-allowed; }
.nota { margin: .5rem 0; color: #64748b; font-size: .82rem; }
.aviso { margin: .5rem 0; padding: .6rem; background: #fffbeb; color: #92400e; border-radius: 7px; font-size: .85rem; }
.avisos { margin: .5rem 0; padding: .6rem 1.4rem; background: #fffbeb; color: #92400e; border-radius: 7px; font-size: .82rem; }
.error { color: #b91c1c; }
.ok { color: #047857; }
.malo { color: #b91c1c; font-weight: 600; }
.previa { margin: .4rem 0; }
.previa summary { cursor: pointer; font-size: .85rem; color: #334155; }
.tabla-wrap { overflow: auto; max-height: 22rem; margin-top: .5rem; border: 1px solid #e2e8f0; border-radius: 7px; }
table { border-collapse: collapse; width: 100%; font-size: .8rem; }
th, td { padding: .35rem .55rem; border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
th { position: sticky; top: 0; background: #f1f5f9; }
.errores td:last-child { white-space: normal; }
.resumen { width: auto; min-width: 28rem; }
</style>
