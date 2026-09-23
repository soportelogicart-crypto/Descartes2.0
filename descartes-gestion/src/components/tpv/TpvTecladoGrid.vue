<script setup lang="ts">
import { computed } from 'vue'
import type { TpvBoton, TpvNivel } from '@/types/tpv'
import {
  TPV_ANCHO_TECLA,
  TPV_COLUMNAS_LEGACY,
  TPV_COLUMNAS_VISIBLES,
  TPV_FILAS_MINIMAS,
} from '@/types/tpv'

const props = defineProps<{
  nivel: TpvNivel | null
  cargando?: boolean
  configurando?: boolean
}>()

const emit = defineEmits<{
  boton: [TpvBoton]
  editar: [TpvBoton]
}>()

const COLUMNAS_LEGACY = TPV_COLUMNAS_LEGACY
const COLUMNAS_VISIBLES = TPV_COLUMNAS_VISIBLES
const ANCHO_LEGACY = TPV_ANCHO_TECLA
const SLOTS_MINIMOS = COLUMNAS_VISIBLES * TPV_FILAS_MINIMAS

function hueco(tecla: number, indice: number): TpvBoton {
  return {
    tecla,
    nivel: props.nivel?.nivel ?? null,
    fila: Math.floor(indice / COLUMNAS_VISIBLES),
    columna: indice % COLUMNAS_VISIBLES,
    etiqueta1: null,
    etiqueta2: null,
    etiqueta3: null,
    ancho: ANCHO_LEGACY,
    alto: 1,
    articulo: null,
    nivelDestino: null,
    nivelVolver: null,
    colorFondo: null,
    colorTexto: null,
    icono: null,
    tarifa: null,
    clase: null,
    tipo: 'vacio',
  }
}

/**
 * Rejilla uniforme: las teclas configuradas se empaquetan por orden y el resto
 * de celdas quedan libres para asignar. Los huecos toman la primera tecla
 * legacy disponible, de modo que al guardarlos DefPlus mantiene su posición.
 */
const celdas = computed<TpvBoton[]>(() => {
  const reales = [...(props.nivel?.botones ?? [])].sort((a, b) => a.tecla - b.tecla)
  const usadas = new Set(reales.map((b) => b.tecla))
  const total = Math.max(
    SLOTS_MINIMOS,
    Math.ceil((reales.length + 1) / COLUMNAS_VISIBLES) * COLUMNAS_VISIBLES
  )

  const resultado = [...reales]
  for (let posicion = 0; resultado.length < total && posicion < 400; posicion++) {
    const fila = Math.floor(posicion / COLUMNAS_VISIBLES)
    const columna = posicion % COLUMNAS_VISIBLES
    const tecla = fila * COLUMNAS_LEGACY + columna * ANCHO_LEGACY
    if (usadas.has(tecla)) continue
    usadas.add(tecla)
    resultado.push(hueco(tecla, resultado.length))
  }
  return resultado
})

const estiloGrid = computed(() => ({
  gridTemplateColumns: `repeat(${COLUMNAS_VISIBLES}, 1fr)`,
}))

function estilo(b: TpvBoton) {
  return {
    background: b.colorFondo ?? undefined,
    color: b.colorTexto ?? undefined,
  }
}

function pulsar(b: TpvBoton) {
  if (props.configurando) {
    emit('editar', b)
  } else if (b.tipo !== 'vacio') {
    emit('boton', b)
  }
}
</script>

<template>
  <div class="teclado">
    <p v-if="cargando" class="teclado-msg">Cargando teclado…</p>
    <!-- Columnas desde la constante compartida: la rejilla y las posiciones
         guardadas en DefPlus tienen que cuadrar siempre. -->
    <div v-else class="teclado-grid" :style="estiloGrid">
      <button
        v-for="b in celdas"
        :key="b.tecla"
        type="button"
        class="tecla"
        :class="{
          vacio: b.tipo === 'vacio',
          articulo: b.tipo === 'articulo',
          nivel: b.tipo === 'nivel',
        }"
        :style="estilo(b)"
        @click="pulsar(b)"
      >
        <span v-if="b.etiqueta1" class="l1">{{ b.etiqueta1 }}</span>
        <span v-if="b.etiqueta2" class="l2">{{ b.etiqueta2 }}</span>
        <span v-if="b.etiqueta3" class="l3">{{ b.etiqueta3 }}</span>
        <span v-if="!b.etiqueta1 && !b.etiqueta2" class="l1 sin">
          {{ b.tipo === 'vacio' ? 'SIN ASIGNAR' : b.articulo || '' }}
        </span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.teclado {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: auto;
  padding: 8px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}

.teclado-msg {
  margin: 0;
  padding: 0.75rem;
  color: #64748b;
  font-size: 0.85rem;
}

/* Todas las teclas miden lo mismo y reparten el alto de la columna: con 10
   por fila el ancho y el alto salen parejos y la tecla queda cuadrada. */
.teclado-grid {
  display: grid;
  flex: 1;
  grid-auto-rows: minmax(3rem, 1fr);
  gap: 6px;
  min-height: 0;
}

.tecla {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0;
  padding: 0.15rem 0.2rem;
  background: #f8fafc;
  color: #1e293b;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  font-weight: 500;
  cursor: pointer;
  touch-action: manipulation;
  overflow: hidden;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.tecla:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}

.tecla:active:not(:disabled) {
  transform: translateY(1px);
}

/* Los grupos llevan tinte propio: se distinguen de los artículos de un vistazo. */
.tecla.nivel {
  background: #eef2ff;
  border-color: #c7d2fe;
  color: #3730a3;
}

.tecla.vacio {
  background: #fff;
  color: #94a3b8;
  border-style: dashed;
  border-color: #cbd5e1;
  font-weight: 400;
}

/* Tecla cuadrada y estrecha: la etiqueta legacy (12 caracteres) parte en dos
   líneas antes que desbordar. */
.l1 {
  font-size: 0.74rem;
  line-height: 1.15;
  text-align: center;
  word-break: break-word;
}

.l2,
.l3 {
  font-size: 0.64rem;
  font-weight: 400;
  line-height: 1;
  text-align: center;
  color: #64748b;
}

.sin {
  font-weight: 400;
}

</style>
