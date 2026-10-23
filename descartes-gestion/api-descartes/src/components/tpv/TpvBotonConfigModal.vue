<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { buscarArticulosTpv, resolverArticuloTpv } from '@/api/tpv'
import TpvTecladoNumerico from '@/components/tpv/TpvTecladoNumerico.vue'
import { extractApiError } from '@/composables/extractApiError'
import {
  imagenTeclado,
  puedeUsarImagenesTeclado,
  recordarImagenTeclado,
} from '@/composables/imagenesTeclado'
import type {
  TpvArticuloPrecio,
  TpvBoton,
  TpvBotonAsignacion,
  TpvZonaTeclado,
} from '@/types/tpv'

const props = defineProps<{
  open: boolean
  boton: TpvBoton | null
  zona: TpvZonaTeclado
  puedeTenerGrupos: boolean
  /** Aviso de borrado de un grupo con botones dentro: pide confirmación. */
  avisoBorrar: string | null
  tarifa: number
}>()

const emit = defineEmits<{
  guardar: [TpvBotonAsignacion]
  borrar: [confirmado: boolean]
  mover: []
  cancelar: []
}>()

/** DefPlus: 12 caracteres por línea de etiqueta, 18 en H_VALOR1. */
const LARGO_ETIQUETA = 12
const LARGO_TEXTO = 18

const PALETA = [
  '#ef4444', '#f97316', '#f59e0b', '#eab308',
  '#84cc16', '#22c55e', '#14b8a6', '#06b6d4',
  '#3b82f6', '#6366f1', '#a855f7', '#ec4899',
]

type Tipo = 'articulo' | 'grupo' | 'texto'

const tipo = ref<Tipo>('articulo')
const codigo = ref('')
const texto = ref('')
const etiquetas = ref<[string, string, string]>(['', '', ''])
const colorFondo = ref<string | null>(null)
const colorTexto = ref<string | null>(null)
const icono = ref<string | null>(null)
const pedirPrecio = ref(false)
const resolviendo = ref(false)
const error = ref<string | null>(null)
const textoBusqueda = ref('')
const resultados = ref<TpvArticuloPrecio[]>([])
const buscando = ref(false)
const ambitoBusqueda = ref<
  'todos' | 'articulo' | 'macrofamilia' | 'familia' | 'subfamilia' | 'agrupacion'
>('todos')

const esGrupoColumna = computed(() => props.zona === 'grupo')
const esNuevo = computed(() =>
  esGrupoColumna.value ? !props.boton?.visible : props.boton?.tipo === 'vacio'
)
const conImagenes = puedeUsarImagenesTeclado()

const titulo = computed(() => {
  if (esGrupoColumna.value) {
    return `GRUPO ${(props.boton?.posicion ?? 0) + 1} DE LA COLUMNA`
  }
  return esNuevo.value ? 'NUEVO BOTÓN' : 'CONFIGURAR BOTÓN'
})

watch(
  () => props.open,
  (open) => {
    if (!open) return
    const b = props.boton
    tipo.value =
      b?.tipo === 'grupo' || b?.tipo === 'grupoVuelta'
        ? 'grupo'
        : b?.tipo === 'texto'
          ? 'texto'
          : 'articulo'
    codigo.value = b?.articulo ?? ''
    texto.value = b?.texto ?? ''
    etiquetas.value = [b?.etiqueta1 ?? '', b?.etiqueta2 ?? '', b?.etiqueta3 ?? '']
    colorFondo.value = b?.colorFondo ?? null
    colorTexto.value = b?.colorTexto ?? null
    icono.value = b?.icono ?? null
    pedirPrecio.value = b?.pedirPrecio === true
    error.value = null
    textoBusqueda.value = ''
    resultados.value = []
    ambitoBusqueda.value = 'todos'
  }
)

/** Reparte un texto en hasta 3 líneas de 12 caracteres sin partir palabras si se puede. */
function repartirEtiquetas(descripcion: string): [string, string, string] {
  const lineas: string[] = []
  let actual = ''
  for (const palabra of descripcion.trim().split(/\s+/).filter(Boolean)) {
    const trozo = palabra.slice(0, LARGO_ETIQUETA)
    const junto = actual ? `${actual} ${trozo}` : trozo
    if (junto.length <= LARGO_ETIQUETA) {
      actual = junto
      continue
    }
    if (actual) lineas.push(actual)
    actual = trozo
    if (lineas.length === 3) break
  }
  if (actual && lineas.length < 3) lineas.push(actual)
  return [lineas[0] ?? '', lineas[1] ?? '', lineas[2] ?? '']
}

function sinEtiquetas(): boolean {
  return etiquetas.value.every((l) => !l.trim())
}

function pulsar(digito: string) {
  if (codigo.value.length < LARGO_TEXTO) codigo.value += digito
}

async function buscarArticulo() {
  const q = textoBusqueda.value.trim()
  if (!q) return
  buscando.value = true
  error.value = null
  try {
    resultados.value = await buscarArticulosTpv(q, {
      tarifa: props.tarifa,
      ambito: ambitoBusqueda.value,
    })
    if (!resultados.value.length) {
      error.value = `Ningún artículo coincide con "${q}"`
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo buscar el artículo')
  } finally {
    buscando.value = false
  }
}

function elegirArticulo(articulo: TpvArticuloPrecio) {
  codigo.value = articulo.codigo
  etiquetas.value = repartirEtiquetas(articulo.descripcion)
  resultados.value = []
}

function clasificacion(articulo: TpvArticuloPrecio): string {
  return [
    articulo.macroFamiliaDescripcion && `Macro: ${articulo.macroFamiliaDescripcion}`,
    articulo.familiaDescripcion && `Familia: ${articulo.familiaDescripcion}`,
    articulo.subfamiliaDescripcion && `Subfamilia: ${articulo.subfamiliaDescripcion}`,
    articulo.agrupacionDescripcion && `Agrupación: ${articulo.agrupacionDescripcion}`,
  ]
    .filter(Boolean)
    .join(' · ')
}

/** Texto negro o blanco según lo claro que sea el fondo. */
function textoParaFondo(hex: string): string {
  const r = parseInt(hex.slice(1, 3), 16)
  const g = parseInt(hex.slice(3, 5), 16)
  const b = parseInt(hex.slice(5, 7), 16)
  return r * 0.299 + g * 0.587 + b * 0.114 > 150 ? '#000000' : '#ffffff'
}

function elegirColor(hex: string | null) {
  colorFondo.value = hex
  colorTexto.value = hex ? textoParaFondo(hex) : null
}

async function elegirImagen() {
  const elegir = window.descartes?.elegirImagenTeclado
  if (!elegir) return
  error.value = null
  const r = await elegir()
  if (!r.ok) {
    error.value = r.message || 'No se pudo usar la imagen'
    return
  }
  if (r.cancelado || !r.ruta) return
  recordarImagenTeclado(r.ruta, r.dataUrl ?? '')
  icono.value = r.ruta
}

const vistaPrevia = computed(() => {
  const img = imagenTeclado(icono.value)
  return {
    backgroundColor: colorFondo.value ?? undefined,
    color: colorTexto.value ?? undefined,
    backgroundImage: img ? `url("${img}")` : undefined,
  }
})

function asignacionBase(t: TpvBotonAsignacion['tipo']): TpvBotonAsignacion {
  return {
    tipo: t,
    etiqueta1: etiquetas.value[0].trim(),
    etiqueta2: etiquetas.value[1].trim(),
    etiqueta3: etiquetas.value[2].trim(),
    colorFondo: colorFondo.value,
    colorTexto: colorTexto.value,
    icono: icono.value,
  }
}

async function confirmar() {
  error.value = null
  if (esGrupoColumna.value || tipo.value === 'grupo') {
    if (sinEtiquetas() && !icono.value) {
      error.value = 'Escriba el nombre del grupo o elija una imagen'
      return
    }
    emit('guardar', asignacionBase('grupo'))
    return
  }

  if (tipo.value === 'texto') {
    const t = texto.value.trim()
    if (!t) {
      error.value = 'Escriba el texto que se añadirá al ticket'
      return
    }
    if (sinEtiquetas()) etiquetas.value = repartirEtiquetas(t)
    emit('guardar', { ...asignacionBase('texto'), texto: t })
    return
  }

  const q = codigo.value.trim()
  if (!q) {
    error.value = 'Busque el artículo o teclee su código'
    return
  }
  resolviendo.value = true
  try {
    const articulo = await resolverArticuloTpv(q, { tarifa: props.tarifa })
    if (sinEtiquetas()) etiquetas.value = repartirEtiquetas(articulo.descripcion)
    emit('guardar', {
      ...asignacionBase('articulo'),
      articulo: articulo.codigo,
      pedirPrecio: pedirPrecio.value,
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Artículo no encontrado')
  } finally {
    resolviendo.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @mousedown.self.prevent>
      <section class="ventana" role="dialog" aria-modal="true">
        <header class="barra">{{ titulo }}</header>

        <div class="cuerpo">
          <p v-if="esGrupoColumna" class="ayuda">
            Los grupos de la columna izquierda abren su página de 14 botones.
            {{ esNuevo ? 'Al guardar entrará en el grupo para rellenarlo.' : '' }}
          </p>

          <div v-else class="tipos">
            <button type="button" :class="{ activo: tipo === 'articulo' }" @click="tipo = 'articulo'">
              ARTÍCULO
            </button>
            <button
              type="button"
              :class="{ activo: tipo === 'grupo' }"
              :disabled="!puedeTenerGrupos && tipo !== 'grupo'"
              :title="puedeTenerGrupos ? '' : 'No caben más grupos dentro de este'"
              @click="tipo = 'grupo'"
            >
              GRUPO
            </button>
            <button type="button" :class="{ activo: tipo === 'texto' }" @click="tipo = 'texto'">
              TEXTO
            </button>
          </div>

          <template v-if="!esGrupoColumna && tipo === 'articulo'">
            <div class="visor">{{ codigo || 'Código de artículo' }}</div>

            <form class="buscador" @submit.prevent="buscarArticulo">
              <select v-model="ambitoBusqueda" aria-label="Buscar por">
                <option value="todos">Todo</option>
                <option value="articulo">Artículo</option>
                <option value="macrofamilia">Macrofamilia</option>
                <option value="familia">Familia</option>
                <option value="subfamilia">Subfamilia</option>
                <option value="agrupacion">Agrupación</option>
              </select>
              <input v-model="textoBusqueda" type="text" placeholder="Código o descripción" />
              <button type="submit" :disabled="buscando || !textoBusqueda.trim()">
                {{ buscando ? '…' : 'BUSCAR' }}
              </button>
            </form>

            <ul v-if="resultados.length" class="resultados">
              <li v-for="a in resultados" :key="a.codigo">
                <button type="button" @click="elegirArticulo(a)">
                  <strong>{{ a.descripcion }}</strong>
                  <small>{{ a.codigo }} · {{ a.precio.toFixed(2) }} €</small>
                  <small v-if="clasificacion(a)" class="clasificacion">
                    {{ clasificacion(a) }}
                  </small>
                </button>
              </li>
            </ul>

            <TpvTecladoNumerico
              v-else
              @tecla="pulsar"
              @borrar="codigo = codigo.slice(0, -1)"
              @limpiar="codigo = ''"
            />

            <label class="check">
              <input v-model="pedirPrecio" type="checkbox" />
              Pedir el precio cada vez que se pulse
            </label>
          </template>

          <template v-else-if="!esGrupoColumna && tipo === 'texto'">
            <label>
              Texto que se añade al ticket
              <input v-model="texto" type="text" :maxlength="LARGO_TEXTO" placeholder="Ej.: SIN SAL" />
            </label>
          </template>

          <p v-else-if="!esGrupoColumna" class="ayuda">
            Un grupo es otra página de 14 botones. {{ esNuevo ? 'Al guardar entrará en él para rellenarlo.' : '' }}
          </p>

          <div class="disenyo">
            <div class="etiquetas">
              <span class="titulo-campo">Texto del botón (3 líneas)</span>
              <input
                v-for="i in 3"
                :key="i"
                v-model="etiquetas[i - 1]"
                type="text"
                :maxlength="LARGO_ETIQUETA"
                :placeholder="i === 1 ? 'Automático si se deja vacío' : ''"
              />
            </div>
            <div class="previa-caja">
              <span class="titulo-campo">Así se verá</span>
              <div class="previa" :style="vistaPrevia">
                <span v-for="(l, i) in etiquetas.filter((x) => x.trim())" :key="i">{{ l }}</span>
              </div>
            </div>
          </div>

          <div>
            <span class="titulo-campo">Color</span>
            <div class="paleta">
              <button
                type="button"
                class="color sin-color"
                :class="{ activo: !colorFondo }"
                title="Sin color"
                @click="elegirColor(null)"
              >
                ✕
              </button>
              <button
                v-for="c in PALETA"
                :key="c"
                type="button"
                class="color"
                :class="{ activo: colorFondo === c }"
                :style="{ background: c }"
                :title="c"
                @click="elegirColor(c)"
              ></button>
            </div>
            <div v-if="colorFondo" class="texto-color">
              <span>Letra:</span>
              <button type="button" :class="{ activo: colorTexto === '#000000' }" @click="colorTexto = '#000000'">
                NEGRA
              </button>
              <button type="button" :class="{ activo: colorTexto === '#ffffff' }" @click="colorTexto = '#ffffff'">
                BLANCA
              </button>
            </div>
          </div>

          <div>
            <span class="titulo-campo">Imagen</span>
            <div class="imagen">
              <template v-if="conImagenes">
                <button type="button" @click="elegirImagen">ELEGIR IMAGEN…</button>
                <button v-if="icono" type="button" @click="icono = null">QUITAR</button>
              </template>
              <small v-else>Las imágenes solo se ven en la aplicación de escritorio de la caja.</small>
              <small v-if="icono" class="ruta" :title="icono">{{ icono }}</small>
            </div>
          </div>

          <p v-if="error" class="error">{{ error }}</p>
        </div>

        <div v-if="avisoBorrar" class="confirma">
          <p>{{ avisoBorrar }}</p>
          <button type="button" class="borrar" @click="emit('borrar', true)">ELIMINAR GRUPO Y BOTONES</button>
          <button type="button" @click="emit('cancelar')">CANCELAR</button>
        </div>

        <footer class="pie">
          <div class="pie-izq">
            <button
              v-if="!esNuevo"
              type="button"
              class="borrar"
              :disabled="resolviendo"
              @click="emit('borrar', false)"
            >
              ELIMINAR
            </button>
            <button
              v-if="!esNuevo"
              type="button"
              :disabled="resolviendo"
              title="Después toque la casilla a la que lo quiere llevar"
              @click="emit('mover')"
            >
              MOVER
            </button>
          </div>
          <button type="button" :disabled="resolviendo" @click="emit('cancelar')">
            CANCELAR
          </button>
          <button type="button" class="guardar" :disabled="resolviendo" @click="confirmar">
            {{ resolviendo ? 'BUSCANDO…' : 'GUARDAR' }}
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 5000;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 55%);
}

.ventana {
  position: relative;
  width: min(34rem, 96vw);
  max-height: 94vh;
  overflow: auto;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-weight: 600;
}

.cuerpo {
  display: grid;
  gap: 0.65rem;
  padding: 0.75rem;
}

.tipos {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
}

button {
  min-height: 2.8rem;
  padding: 0.4rem 0.6rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

button:hover:not(:disabled):not(.activo) {
  background: #f1f5f9;
  border-color: #94a3b8;
}

button:disabled {
  opacity: 0.5;
  cursor: default;
}

button:active {
  transform: translateY(1px);
}

button.activo {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}

.visor {
  padding: 0.5rem 0.7rem;
  background: #0f172a;
  border-radius: 10px;
  color: #34d399;
  font: 600 1.35rem 'Cascadia Mono', Consolas, monospace;
  text-align: right;
}

.buscador {
  display: grid;
  grid-template-columns: 8rem 1fr auto;
  gap: 4px;
}

.resultados {
  display: grid;
  gap: 3px;
  max-height: 16rem;
  margin: 0;
  padding: 0;
  overflow: auto;
  list-style: none;
}

.resultados button {
  display: grid;
  width: 100%;
  text-align: left;
}

.resultados small {
  font-weight: 400;
}

.resultados .clasificacion {
  margin-top: 0.15rem;
  color: #64748b;
  font-size: 0.72rem;
}

.ayuda {
  margin: 0;
  color: #64748b;
  font-size: 0.8rem;
}

label {
  display: grid;
  gap: 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #475569;
}

label.check {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

label.check input {
  width: 1.3rem;
  min-height: 1.3rem;
}

.titulo-campo {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #475569;
}

input,
select {
  min-height: 2.7rem;
  padding: 0.35rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #0f172a;
  font: inherit;
}

input:focus,
select:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
}

.disenyo {
  display: grid;
  grid-template-columns: 1fr 9rem;
  gap: 0.6rem;
}

.etiquetas {
  display: grid;
  gap: 4px;
}

.previa {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 7.6rem;
  padding: 0.3rem;
  background-color: #f8fafc;
  background-position: center;
  background-repeat: no-repeat;
  background-size: contain;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  color: #1e293b;
  font-size: 0.82rem;
  font-weight: 600;
  text-align: center;
  overflow: hidden;
}

.paleta {
  display: grid;
  grid-template-columns: repeat(13, 1fr);
  gap: 4px;
}

.color {
  min-height: 2.2rem;
  padding: 0;
  border: 2px solid transparent;
}

.color.activo {
  border-color: #0f172a;
  box-shadow: 0 0 0 2px #fff inset;
}

.sin-color {
  background: #fff;
  border-color: #cbd5e1;
  color: #64748b;
}

.sin-color.activo {
  background: #fff;
  color: #0f172a;
}

.texto-color {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 4px;
  font-size: 0.8rem;
}

.imagen {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.imagen small {
  color: #64748b;
}

.ruta {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 34rem) {
  .buscador {
    grid-template-columns: 1fr auto;
  }

  .buscador select {
    grid-column: 1 / -1;
  }

  .disenyo {
    grid-template-columns: 1fr;
  }

  .paleta {
    grid-template-columns: repeat(7, 1fr);
  }
}

.error {
  margin: 0;
  padding: 0.5rem 0.65rem;
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 10px;
  color: #be123c;
}

.aviso-borrar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.confirma {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: grid;
  align-content: center;
  gap: 0.6rem;
  padding: 1.25rem;
  background: rgb(255 255 255 / 96%);
  text-align: center;
}

.confirma p {
  margin: 0;
  color: #be123c;
  font-weight: 600;
}

.pie {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 6px;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.pie-izq {
  display: flex;
  gap: 6px;
}

.borrar {
  background: #fff1f2;
  border-color: #fecdd3;
  color: #be123c;
}

.guardar {
  background: #059669;
  border-color: #059669;
  color: #fff;
}

.guardar:hover:not(:disabled) {
  background: #047857;
  border-color: #047857;
}
</style>
