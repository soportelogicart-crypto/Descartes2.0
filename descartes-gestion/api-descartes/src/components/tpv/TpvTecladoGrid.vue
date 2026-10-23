<script setup lang="ts">
import { computed } from 'vue'
import { imagenTeclado } from '@/composables/imagenesTeclado'
import type { TpvBoton, TpvNivel, TpvZonaTeclado } from '@/types/tpv'
import { TPV_POSICIONES_BOTON, TPV_POSICIONES_GRUPO } from '@/types/tpv'

const props = defineProps<{
  nivel: TpvNivel | null
  cargando?: boolean
  configurando?: boolean
  /** Posición marcada para mover: el siguiente toque la intercambia. */
  moviendo?: { zona: TpvZonaTeclado; posicion: number } | null
}>()

const emit = defineEmits<{
  boton: [TpvBoton, TpvZonaTeclado]
  editar: [TpvBoton, TpvZonaTeclado]
  /** Grupo ya creado: en configuración se entra para ponerle botones. */
  entrar: [TpvBoton, TpvZonaTeclado]
}>()

function hueco(posicion: number): TpvBoton {
  return {
    posicion,
    tecla: -1,
    etiqueta1: null,
    etiqueta2: null,
    etiqueta3: null,
    colorFondo: null,
    colorTexto: null,
    icono: null,
    tipo: 'vacio',
    nivelDestino: null,
    visible: false,
  }
}

function rellenar(lista: TpvBoton[], total: number): TpvBoton[] {
  const porPosicion = new Map(lista.map((b) => [b.posicion, b]))
  return Array.from({ length: total }, (_, i) => porPosicion.get(i) ?? hueco(i))
}

const grupos = computed(() => rellenar(props.nivel?.grupos ?? [], TPV_POSICIONES_GRUPO))
const botones = computed(() => rellenar(props.nivel?.botones ?? [], TPV_POSICIONES_BOTON))

/** Legacy oculta la columna de grupos si ninguno tiene texto ni imagen. */
const conGrupos = computed(() => props.configurando || grupos.value.some((g) => g.visible))

/** Grupo abierto: los tres primeros caracteres del nivel actual (001 → grupo 0). */
const grupoActivo = computed(() => {
  const n = props.nivel?.nivel ?? ''
  return /^\d{3}/.test(n) ? Number(n.slice(0, 3)) - 1 : -1
})

function estilo(b: TpvBoton) {
  const img = imagenTeclado(b.icono)
  return {
    backgroundColor: b.colorFondo ?? undefined,
    color: b.colorTexto ?? undefined,
    backgroundImage: img ? `url("${img}")` : undefined,
  }
}

function lineas(b: TpvBoton): string[] {
  return [b.etiqueta1, b.etiqueta2, b.etiqueta3].filter((l): l is string => !!l)
}

function marcado(zona: TpvZonaTeclado, b: TpvBoton): boolean {
  return props.moviendo?.zona === zona && props.moviendo.posicion === b.posicion
}

/** Grupo con página propia: al tocarlo se entra, no se abre el editor. */
function esGrupoNavegable(b: TpvBoton, zona: TpvZonaTeclado): boolean {
  if (zona === 'grupo') return b.visible
  return b.tipo === 'grupo' || b.tipo === 'grupoVuelta'
}

function pulsar(b: TpvBoton, zona: TpvZonaTeclado) {
  if (props.configurando) {
    if (esGrupoNavegable(b, zona)) emit('entrar', b, zona)
    else emit('editar', b, zona)
    return
  }
  if (zona === 'grupo' ? b.visible : b.tipo !== 'vacio') emit('boton', b, zona)
}
</script>

<template>
  <div class="teclado" :class="{ configurando }">
    <p v-if="cargando && !nivel" class="teclado-msg">Cargando teclado…</p>
    <div v-else class="teclado-cuerpo" :class="{ 'sin-grupos': !conGrupos }">
      <div v-if="conGrupos" class="col-grupos">
        <button
          v-for="g in grupos"
          :key="`g${g.posicion}`"
          type="button"
          class="tecla grupo"
          :class="{
            oculto: !g.visible && !configurando,
            vacio: !g.visible,
            activo: g.posicion === grupoActivo,
            marcado: marcado('grupo', g),
            'con-imagen': !!imagenTeclado(g.icono),
          }"
          :style="g.visible ? estilo(g) : undefined"
          :disabled="!g.visible && !configurando"
          @click="pulsar(g, 'grupo')"
        >
          <template v-if="g.visible">
            <span v-for="(l, i) in lineas(g)" :key="i" class="linea">{{ l }}</span>
            <span
              v-if="configurando"
              class="lapiz"
              title="Editar o eliminar este grupo"
              @click.stop="emit('editar', g, 'grupo')"
            >✎</span>
          </template>
          <span v-else-if="configurando" class="mas">+</span>
        </button>
      </div>

      <div class="rejilla">
        <button
          v-for="b in botones"
          :key="`b${b.posicion}`"
          type="button"
          class="tecla"
          :class="{
            vacio: b.tipo === 'vacio',
            grupo: b.tipo === 'grupo' || b.tipo === 'grupoVuelta',
            texto: b.tipo === 'texto',
            marcado: marcado('boton', b),
            'con-imagen': !!imagenTeclado(b.icono),
          }"
          :style="b.tipo !== 'vacio' ? estilo(b) : undefined"
          :disabled="b.tipo === 'vacio' && !configurando"
          @click="pulsar(b, 'boton')"
        >
          <template v-if="b.tipo !== 'vacio'">
            <span v-for="(l, i) in lineas(b)" :key="i" class="linea">{{ l }}</span>
            <span v-if="!lineas(b).length && !b.icono" class="linea sin">
              {{ b.articulo || b.texto || '' }}
            </span>
            <span
              v-if="configurando && (b.tipo === 'grupo' || b.tipo === 'grupoVuelta')"
              class="lapiz"
              title="Editar o eliminar este grupo"
              @click.stop="emit('editar', b, 'boton')"
            >✎</span>
          </template>
          <span v-else-if="configurando" class="mas">+</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.teclado {
  display: flex;
  flex-direction: column;
  min-height: 0;
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

/* Legacy frmVenta: columna de 8 grupos a la izquierda y 14 botones en 2x7. */
.teclado-cuerpo {
  display: grid;
  flex: 1;
  grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
  gap: 8px;
  min-height: 0;
}

.teclado-cuerpo.sin-grupos {
  grid-template-columns: minmax(0, 1fr);
}

.col-grupos {
  display: grid;
  grid-template-rows: repeat(8, minmax(2.6rem, 1fr));
  gap: 5px;
  min-height: 0;
}

.rejilla {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  grid-template-rows: repeat(7, minmax(3rem, 1fr));
  gap: 6px;
  min-height: 0;
}

.tecla {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.05rem;
  min-width: 0;
  padding: 0.2rem 0.3rem;
  background-color: #f8fafc;
  background-position: center;
  background-repeat: no-repeat;
  background-size: contain;
  color: #1e293b;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
  overflow: hidden;
  transition: filter 0.12s ease, transform 0.06s ease;
}

.tecla:hover:not(:disabled) {
  filter: brightness(0.96);
}

.tecla:active:not(:disabled) {
  transform: translateY(1px);
}

.tecla.grupo:not(.vacio) {
  background-color: #eef2ff;
  border-color: #c7d2fe;
  color: #3730a3;
}

.tecla.texto:not(.vacio) {
  font-style: italic;
}

.tecla.activo {
  outline: 3px solid #2563eb;
  outline-offset: -3px;
}

.tecla.marcado {
  outline: 3px dashed #f59e0b;
  outline-offset: -3px;
}

.tecla.vacio {
  background: #fff;
  color: #94a3b8;
  border-style: dashed;
  border-color: #e2e8f0;
  cursor: default;
}

.configurando .tecla.vacio {
  cursor: pointer;
  border-color: #94a3b8;
}

.tecla.oculto {
  visibility: hidden;
}

/* Legacy pinta el texto encima de la imagen: se le da sombra para que se lea. */
.tecla.con-imagen .linea {
  text-shadow: 0 0 3px #fff, 0 0 3px #fff;
}

.linea {
  max-width: 100%;
  font-size: 0.82rem;
  line-height: 1.15;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.linea + .linea {
  font-size: 0.74rem;
  font-weight: 500;
}

.sin {
  font-weight: 400;
}

.mas {
  font-size: 1.4rem;
  font-weight: 300;
  line-height: 1;
}

.lapiz {
  position: absolute;
  top: 2px;
  right: 2px;
  z-index: 1;
  padding: 0 0.28rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  color: #0f172a;
  font-size: 0.75rem;
  line-height: 1.3;
}
</style>
