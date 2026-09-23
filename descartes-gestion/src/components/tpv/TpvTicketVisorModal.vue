<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import type { TpvLineaBorrador } from '@/types/tpv'

const props = defineProps<{
  open: boolean
  lineas: TpvLineaBorrador[]
  total: string
  seleccion: number
}>()

const emit = defineEmits<{
  seleccionar: [number]
  cerrar: []
}>()

const cuerpo = ref<HTMLElement | null>(null)

/** Al abrir, el visor arranca donde está la línea marcada, no al principio. */
watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) return
    await nextTick()
    cuerpo.value?.querySelector('tr.sel')?.scrollIntoView({ block: 'center' })
  }
)
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @mousedown.prevent>
      <section class="ventana" role="dialog" aria-modal="true">
        <header class="barra">
          <span>TICKET COMPLETO</span>
          <span class="conteo">{{ lineas.length }} líneas</span>
        </header>

        <div ref="cuerpo" class="cuerpo">
          <table class="visor-grid">
            <thead>
              <tr>
                <th class="c-pos">#</th>
                <th class="c-cant">Cant.</th>
                <th class="c-desc">Descripcion</th>
                <th class="c-num">Precio</th>
                <th class="c-dto">Dto.</th>
                <th class="c-num">Importe</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(l, i) in lineas"
                :key="`${l.articulo}-${i}`"
                :class="{ sel: i === seleccion }"
                @click="emit('seleccionar', i)"
              >
                <td class="c-pos">{{ i + 1 }}</td>
                <td class="c-cant">{{ l.cantidad }}</td>
                <td class="c-desc">
                  <span class="desc">{{ l.descripcion || l.articulo }}</span>
                  <span class="cod">{{ l.articulo }}</span>
                </td>
                <td class="c-num">{{ l.precio.toFixed(2) }}</td>
                <td class="c-dto">{{ l.pjeDto ? `${l.pjeDto.toFixed(2)}%` : '—' }}</td>
                <td class="c-num">{{ l.importe.toFixed(2) }}</td>
              </tr>
              <tr v-if="!lineas.length" class="vacia">
                <td colspan="6">El ticket no tiene líneas</td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="pie">
          <div class="total">
            <span class="lbl">TOTAL</span>
            <span class="val">{{ total }}</span>
          </div>
          <button type="button" class="cerrar" @click="emit('cerrar')">CERRAR</button>
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

/* Franja central de arriba a abajo: deja ver que el TPV sigue detrás. */
.ventana {
  display: flex;
  flex-direction: column;
  width: min(46rem, 62vw);
  height: 96vh;
  overflow: hidden;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.barra {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-size: 0.85rem;
  font-weight: 600;
}

.conteo {
  color: #94a3b8;
  font-size: 0.75rem;
  font-weight: 500;
}

.cuerpo {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 0.75rem;
}

.visor-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 1rem;
}

.visor-grid thead {
  position: sticky;
  top: 0;
  z-index: 1;
}

.visor-grid th {
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  padding: 0.5rem 0.55rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  text-align: left;
  color: #64748b;
}

.visor-grid td {
  border-bottom: 1px solid #f1f5f9;
  padding: 0.55rem;
  color: #1e293b;
  vertical-align: top;
}

.visor-grid tbody tr {
  cursor: pointer;
}

.visor-grid tbody tr:hover td {
  background: #f8fafc;
}

.visor-grid tbody tr.sel td {
  background: #2563eb;
  color: #fff;
}

.c-pos {
  width: 2.6rem;
  color: #94a3b8;
  text-align: right;
}

.c-cant {
  width: 4rem;
  text-align: right;
}

.c-num {
  width: 6rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.c-dto {
  width: 5rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.desc {
  display: block;
}

.cod {
  display: block;
  font-size: 0.75rem;
  color: #94a3b8;
}

.sel .c-pos,
.sel .cod {
  color: #bfdbfe;
}

.vacia td {
  text-align: center;
  color: #94a3b8;
  cursor: default;
}

.vacia td:hover {
  background: #fff;
}

.pie {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.total {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  padding: 0.45rem 0.8rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  border-radius: 12px;
  color: #f8fafc;
}

.lbl {
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  color: #94a3b8;
}

.val {
  margin-left: auto;
  font-size: 1.6rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.cerrar {
  min-width: 10rem;
  min-height: 2.9rem;
  background: #e2e8f0;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #334155;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.cerrar:active {
  transform: translateY(1px);
}
</style>
