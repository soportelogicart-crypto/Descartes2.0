<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  arbolExclusionFidelizacion,
  type FidelizacionExclusion,
  type FidelizacionNodoArbol,
} from '@/api/ventas'
import { extractApiError } from '@/composables/extractApiError'

const props = withDefaults(defineProps<{ readonly?: boolean }>(), { readonly: false })
const model = defineModel<FidelizacionExclusion[]>({ required: true })

const MAX_ARTICULOS_NODO = 500
const RAIZ = ''

const NIVELES: Record<FidelizacionExclusion['tipo'], string> = {
  M: 'Macrofamilia',
  F: 'Familia',
  S: 'Subfamilia',
  A: 'Artículo',
}

const hijos = ref(new Map<string, FidelizacionNodoArbol[]>())
const abiertos = ref(new Set<string>())
const cargando = ref(new Set<string>())
const error = ref('')

type Fila = { nodo: FidelizacionNodoArbol; clave: string; nivel: number; heredado: boolean }

function claveDe(n: { tipo: string; codigo: string }): string {
  return `${n.tipo}:${n.codigo.toUpperCase()}`
}

const excluidos = computed(() => new Set(model.value.map(claveDe)))

const filas = computed<Fila[]>(() => {
  const salida: Fila[] = []
  const recorrer = (padre: string, nivel: number, heredado: boolean) => {
    for (const nodo of hijos.value.get(padre) ?? []) {
      const clave = claveDe(nodo)
      salida.push({ nodo, clave, nivel, heredado })
      if (abiertos.value.has(clave)) {
        recorrer(clave, nivel + 1, heredado || excluidos.value.has(clave))
      }
    }
  }
  recorrer(RAIZ, 0, false)
  return salida
})

onMounted(() => cargar(RAIZ, '', ''))

async function cargar(clave: string, nivel: string, codigo: string) {
  if (hijos.value.has(clave) || cargando.value.has(clave)) return
  cargando.value.add(clave)
  error.value = ''
  try {
    hijos.value.set(clave, await arbolExclusionFidelizacion(nivel, codigo))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el árbol de artículos')
  } finally {
    cargando.value.delete(clave)
  }
}

function alternar(fila: Fila) {
  if (!fila.nodo.hijos) return
  if (abiertos.value.has(fila.clave)) {
    abiertos.value.delete(fila.clave)
    return
  }
  abiertos.value.add(fila.clave)
  void cargar(fila.clave, fila.nodo.tipo, fila.nodo.codigo)
}

/** Claves ya cargadas bajo un nodo: al excluir el padre sobran sus exclusiones. */
function descendientesCargados(clave: string): Set<string> {
  const salida = new Set<string>()
  const pila = [clave]
  while (pila.length) {
    for (const hijo of hijos.value.get(pila.pop() as string) ?? []) {
      const c = claveDe(hijo)
      salida.add(c)
      pila.push(c)
    }
  }
  return salida
}

function marcar(fila: Fila, excluir: boolean) {
  if (props.readonly || fila.heredado) return
  if (!excluir) {
    model.value = model.value.filter((e) => claveDe(e) !== fila.clave)
    return
  }
  const debajo = descendientesCargados(fila.clave)
  model.value = [
    ...model.value.filter((e) => !debajo.has(claveDe(e))),
    { tipo: fila.nodo.tipo, codigo: fila.nodo.codigo, descripcion: fila.nodo.descripcion },
  ]
}

function quitar(exclusion: FidelizacionExclusion) {
  if (props.readonly) return
  const clave = claveDe(exclusion)
  model.value = model.value.filter((e) => claveDe(e) !== clave)
}

function etiqueta(n: { codigo: string; descripcion: string }): string {
  if (!n.codigo) return n.descripcion || '(Sin código)'
  return n.descripcion ? `${n.codigo} ${n.descripcion}` : n.codigo
}
</script>

<template>
  <div class="arbol-exclusion">
    <div class="arbol" role="tree">
      <p v-if="cargando.has(RAIZ)" class="nota">Cargando…</p>
      <div
        v-for="fila in filas"
        :key="fila.clave"
        class="fila"
        :class="{ heredado: fila.heredado, excluido: excluidos.has(fila.clave) }"
        :style="{ paddingLeft: `${0.2 + fila.nivel * 1.1}rem` }"
        role="treeitem"
        :aria-expanded="fila.nodo.hijos ? abiertos.has(fila.clave) : undefined"
      >
        <button
          type="button"
          class="toggle"
          :class="{ oculto: !fila.nodo.hijos }"
          :tabindex="fila.nodo.hijos ? 0 : -1"
          @click="alternar(fila)"
        >
          {{ abiertos.has(fila.clave) ? '▾' : '▸' }}
        </button>
        <label class="nodo">
          <input
            type="checkbox"
            :checked="fila.heredado || excluidos.has(fila.clave)"
            :disabled="readonly || fila.heredado"
            @change="marcar(fila, ($event.target as HTMLInputElement).checked)"
          />
          <span class="nivel">{{ NIVELES[fila.nodo.tipo] }}</span>
          <span class="texto">{{ etiqueta(fila.nodo) }}</span>
        </label>
        <span v-if="cargando.has(fila.clave)" class="nota">Cargando…</span>
        <span
          v-else-if="abiertos.has(fila.clave) && (hijos.get(fila.clave)?.length ?? 0) === 0"
          class="nota"
        >
          Vacío
        </span>
        <span
          v-else-if="fila.nodo.tipo === 'S' && (hijos.get(fila.clave)?.length ?? 0) >= MAX_ARTICULOS_NODO"
          class="nota"
        >
          Solo los {{ MAX_ARTICULOS_NODO }} primeros
        </span>
      </div>
    </div>
    <p v-if="error" class="error">{{ error }}</p>

    <div class="resumen">
      <span class="titulo">Excluidos ({{ model.length }})</span>
      <p v-if="model.length === 0" class="nota">Ninguno: todos los artículos suman puntos.</p>
      <ul v-else>
        <li v-for="e in model" :key="`${e.tipo}:${e.codigo}`">
          <span class="nivel">{{ NIVELES[e.tipo] }}</span>
          <span class="texto">{{ etiqueta(e) }}</span>
          <button v-if="!readonly" type="button" class="quitar" title="Quitar exclusión" @click="quitar(e)">
            ✕
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.arbol-exclusion {
  display: grid;
  gap: 0.45rem;
}

.arbol {
  max-height: 22rem;
  overflow: auto;
  border: 1px solid #c5cdd8;
  border-radius: 3px;
  background: #fff;
  padding: 0.2rem 0;
}

.fila {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  min-height: 1.55rem;
  padding-right: 0.4rem;
  font-size: 0.78rem;
}

.fila:hover {
  background: #f1f5f9;
}

.fila.excluido .texto {
  color: #b91c1c;
  text-decoration: line-through;
}

.fila.heredado .texto,
.fila.heredado .nivel {
  color: #94a3b8;
}

.toggle {
  width: 1.2rem;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
  color: #475569;
  font-size: 0.75rem;
}

.toggle.oculto {
  visibility: hidden;
}

.nodo {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
  cursor: pointer;
}

.nodo input {
  margin: 0;
}

.nivel {
  flex: none;
  font-size: 0.68rem;
  color: #64748b;
  text-transform: uppercase;
}

.texto {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.nota {
  margin: 0;
  font-size: 0.72rem;
  color: #64748b;
}

.fila .nota {
  margin-left: auto;
}

.resumen {
  display: grid;
  gap: 0.25rem;
}

.resumen .titulo {
  font-size: 0.75rem;
  font-weight: 600;
}

.resumen ul {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.2rem;
  max-height: 9rem;
  overflow: auto;
}

.resumen li {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.78rem;
}

.quitar {
  margin-left: auto;
  padding: 0 0.35rem;
  border: 1px solid #fecaca;
  border-radius: 4px;
  background: #fff;
  color: #b91c1c;
  cursor: pointer;
  font-size: 0.7rem;
}

.error {
  margin: 0;
  color: #b91c1c;
  font-size: 0.78rem;
}
</style>
