<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { buscarArticulosTpv, resolverArticuloTpv } from '@/api/tpv'
import TpvTecladoNumerico from '@/components/tpv/TpvTecladoNumerico.vue'
import { extractApiError } from '@/composables/extractApiError'
import type {
  TpvArticuloPrecio,
  TpvBoton,
  TpvBotonAsignacion,
  TpvNivelResumen,
} from '@/types/tpv'
import { TPV_ANCHO_TECLA } from '@/types/tpv'

const props = defineProps<{
  open: boolean
  boton: TpvBoton | null
  nivelActual: string
  niveles: TpvNivelResumen[]
  tarifa: number
}>()

const emit = defineEmits<{
  guardar: [TpvBotonAsignacion]
  borrar: []
  cancelar: []
}>()

const ANCHO_TECLA = TPV_ANCHO_TECLA

const tipo = ref<'articulo' | 'nivel'>('articulo')
const codigo = ref('')
const nivelDestino = ref('')
const etiqueta = ref('')
const resolviendo = ref(false)
const error = ref<string | null>(null)
const textoBusqueda = ref('')
const resultados = ref<TpvArticuloPrecio[]>([])
const buscando = ref(false)
const crearGrupo = ref(false)
const ambitoBusqueda = ref<
  'todos' | 'articulo' | 'macrofamilia' | 'familia' | 'subfamilia' | 'agrupacion'
>('todos')

const grupos = computed(() => props.niveles.filter((n) => n.nivel !== props.nivelActual))

watch(
  () => props.open,
  (open) => {
    if (!open) return
    const b = props.boton
    tipo.value = b?.tipo === 'nivel' ? 'nivel' : 'articulo'
    codigo.value = b?.articulo ?? ''
    nivelDestino.value = b?.nivelDestino ?? ''
    etiqueta.value = b?.etiqueta1 ?? ''
    error.value = null
    textoBusqueda.value = ''
    resultados.value = []
    ambitoBusqueda.value = 'todos'
    crearGrupo.value = b?.tipo !== 'nivel' && grupos.value.length === 0
  }
)

function elegirGrupoNuevo() {
  crearGrupo.value = true
  nivelDestino.value = ''
  error.value = null
}

function elegirGrupoExistente(grupo: TpvNivelResumen) {
  crearGrupo.value = false
  nivelDestino.value = grupo.nivel
  if (!etiqueta.value.trim()) etiqueta.value = grupo.etiqueta.slice(0, 12)
  error.value = null
}

function pulsar(digito: string) {
  if (codigo.value.length < 20) codigo.value += digito
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
  // La etiqueta legacy solo admite 12 caracteres.
  if (!etiqueta.value.trim()) etiqueta.value = articulo.descripcion.slice(0, 12)
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

async function confirmar() {
  error.value = null
  if (tipo.value === 'nivel') {
    if (crearGrupo.value) {
      emit('guardar', {
        tipo: 'nivel',
        nivelDestino: '',
        crearGrupo: true,
        etiqueta: etiqueta.value.trim(),
        ancho: ANCHO_TECLA,
        alto: 1,
      })
      return
    }
    const grupo = grupos.value.find((n) => n.nivel === nivelDestino.value)
    if (!grupo) {
      error.value = 'Pulse CREAR GRUPO NUEVO o toque uno de los grupos de la lista'
      return
    }
    emit('guardar', {
      tipo: 'nivel',
      nivelDestino: grupo.nivel,
      etiqueta: etiqueta.value.trim() || grupo.etiqueta,
      ancho: ANCHO_TECLA,
      alto: 1,
    })
    return
  }

  const q = codigo.value.trim()
  if (!q) {
    error.value = 'Introduzca el código del artículo'
    return
  }
  resolviendo.value = true
  try {
    const articulo = await resolverArticuloTpv(q, { tarifa: props.tarifa })
    emit('guardar', {
      tipo: 'articulo',
      articulo: articulo.codigo,
      etiqueta: etiqueta.value.trim() || articulo.descripcion,
      ancho: ANCHO_TECLA,
      alto: 1,
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
        <header class="barra">
          CONFIGURAR BOTÓN {{ boton?.tecla }}
        </header>

        <div class="cuerpo">
          <div class="tipos">
            <button
              type="button"
              :class="{ activo: tipo === 'articulo' }"
              @click="tipo = 'articulo'"
            >
              ARTÍCULO
            </button>
            <button
              type="button"
              :class="{ activo: tipo === 'nivel' }"
              @click="tipo = 'nivel'"
            >
              GRUPO DE BOTONES
            </button>
          </div>

          <template v-if="tipo === 'articulo'">
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
              <input
                v-model="textoBusqueda"
                type="text"
                placeholder="Código o descripción"
              />
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
          </template>

          <template v-else>
            <p class="ayuda">
              Un grupo es una página de botones. Al pulsar esta tecla durante la venta
              se abre esa página, y se vuelve aquí con ATRÁS.
            </p>

            <button
              type="button"
              class="nuevo"
              :class="{ activo: crearGrupo }"
              @click="elegirGrupoNuevo"
            >
              <strong>+ CREAR GRUPO NUEVO</strong>
              <small>Se crea vacío y entrarás en él para asignar sus artículos</small>
            </button>

            <p v-if="grupos.length" class="ayuda">…o abrir un grupo que ya existe:</p>
            <div v-if="grupos.length" class="grupos">
              <button
                v-for="grupo in grupos"
                :key="grupo.nivel"
                type="button"
                :class="{ activo: !crearGrupo && nivelDestino === grupo.nivel }"
                @click="elegirGrupoExistente(grupo)"
              >
                <strong>{{ grupo.etiqueta }}</strong>
                <small>Nivel {{ grupo.nivel }} · {{ grupo.botones }} botones</small>
              </button>
            </div>
          </template>

          <label>
            Texto del botón
            <input
              v-model="etiqueta"
              type="text"
              maxlength="12"
              placeholder="Automático si se deja vacío"
            />
          </label>

          <p v-if="error" class="error">{{ error }}</p>
        </div>

        <footer class="pie">
          <button
            v-if="boton?.tipo !== 'vacio'"
            type="button"
            class="borrar"
            :disabled="resolviendo"
            @click="emit('borrar')"
          >
            DEJAR VACÍO
          </button>
          <span></span>
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
  width: min(31rem, 96vw);
  max-height: 94vh;
  overflow: auto;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  color: #000;
  font-family: 'Segoe UI', Tahoma, sans-serif;
}

.barra {
  padding: 0.4rem 0.65rem;
  background: linear-gradient(#00309c, #000060);
  color: #fff;
  font-weight: 700;
}

.cuerpo {
  display: grid;
  gap: 0.65rem;
  padding: 0.7rem;
}

.tipos {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
}

button {
  min-height: 2.8rem;
  padding: 0.4rem 0.6rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  border-radius: 0;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

button:active,
button.activo {
  border-style: inset;
}

button.activo {
  background: #a8c4ec;
}

.visor {
  padding: 0.5rem;
  background: #000;
  border: 2px inset #808080;
  color: #30ff30;
  font: 700 1.35rem Consolas, monospace;
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
  color: #404060;
  font-size: 0.72rem;
}

.ayuda {
  margin: 0;
  color: #303030;
  font-size: 0.8rem;
}

.nuevo {
  display: grid;
  background: #c8e0c8;
  text-align: left;
}

.nuevo small {
  font-weight: 400;
}

.grupos {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 4px;
  max-height: 14rem;
  overflow: auto;
}

.grupos button {
  display: grid;
  text-align: left;
}

.grupos small {
  font-weight: 400;
}

label {
  display: grid;
  gap: 0.25rem;
  font-weight: 700;
}

input,
select {
  min-height: 2.7rem;
  padding: 0.35rem 0.5rem;
  border: 2px inset #f5f5f5;
  border-radius: 0;
  font: inherit;
}

@media (max-width: 34rem) {
  .buscador {
    grid-template-columns: 1fr auto;
  }

  .buscador select {
    grid-column: 1 / -1;
  }
}

.error {
  margin: 0;
  padding: 0.45rem;
  background: #ffd7d7;
  border: 1px solid #a00000;
  color: #800000;
}

.pie {
  display: grid;
  grid-template-columns: auto 1fr auto auto;
  gap: 4px;
  padding: 0 0.7rem 0.7rem;
}

.borrar {
  background: #f0c8c8;
  color: #740000;
}

.guardar {
  background: #c8e0c8;
}
</style>
