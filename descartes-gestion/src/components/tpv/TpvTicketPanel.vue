<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import type { TpvLineaBorrador } from '@/types/tpv'

const props = defineProps<{
  lineas: TpvLineaBorrador[]
  total: string
  seleccion: number
  guardando?: boolean
}>()

const emit = defineEmits<{
  seleccionar: [number]
  ver: []
}>()

const grid = ref<HTMLElement | null>(null)

/**
 * En una venta larga la última línea cae fuera del recuadro: el ticket sigue a
 * la línea marcada para que el cajero vea siempre lo que acaba de vender.
 */
watch(
  () => [props.seleccion, props.lineas.length],
  async () => {
    if (props.seleccion < 0) return
    await nextTick()
    grid.value?.querySelector('tr.sel')?.scrollIntoView({ block: 'nearest' })
  }
)
</script>

<template>
  <div class="ticket">
    <table ref="grid" class="ticket-grid">
      <thead>
        <tr>
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
          <td colspan="5">Pulse un articulo en el teclado</td>
        </tr>
      </tbody>
    </table>

    <div class="ticket-total">
      <button
        type="button"
        class="ver-ticket"
        :disabled="!lineas.length"
        title="Ver el ticket completo"
        @click="emit('ver')"
      >
        VER TICKET
      </button>
      <span class="lbl">TOTAL</span>
      <span class="val">{{ total }}</span>
      <span v-if="guardando" class="grabando">grabando…</span>
    </div>
  </div>
</template>

<style scoped>
.ticket {
  display: flex;
  flex-direction: column;
  min-height: 0;
  gap: 6px;
}

.ticket-grid {
  flex: 1;
  display: block;
  overflow-y: auto;
  min-height: 0;
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  font-size: 0.85rem;
}

.ticket-grid thead,
.ticket-grid tbody,
.ticket-grid tr {
  display: table;
  width: 100%;
  table-layout: fixed;
}

.ticket-grid thead {
  position: sticky;
  top: 0;
  z-index: 1;
}

.ticket-grid th {
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  padding: 0.4rem 0.45rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  text-align: left;
  color: #64748b;
}

.ticket-grid td {
  border-bottom: 1px solid #f1f5f9;
  padding: 0.35rem 0.45rem;
  color: #1e293b;
  vertical-align: top;
}

.ticket-grid tbody tr {
  cursor: pointer;
}

.ticket-grid tbody tr:hover td {
  background: #f8fafc;
}

.ticket-grid tbody tr.sel td {
  background: #2563eb;
  color: #fff;
}

/* Columna estrecha: los numéricos se aprietan para que la descripción respire. */
.c-cant {
  width: 2.8rem;
  text-align: right;
}

.c-num {
  width: 4.2rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.c-dto {
  width: 3.4rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.desc {
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.cod {
  display: block;
  font-size: 0.7rem;
  color: #94a3b8;
}

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

.ticket-total {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  padding: 0.5rem 0.8rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  border-radius: 12px;
  color: #f8fafc;
}

.ver-ticket {
  align-self: center;
  padding: 0.3rem 0.55rem;
  background: rgb(248 250 252 / 12%);
  border: 1px solid rgb(148 163 184 / 45%);
  border-radius: 8px;
  color: #e2e8f0;
  font: inherit;
  font-size: 0.66rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  cursor: pointer;
}

.ver-ticket:disabled {
  opacity: 0.45;
  cursor: default;
}

.ver-ticket:not(:disabled):active {
  transform: translateY(1px);
}

.lbl {
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  color: #94a3b8;
}

.val {
  margin-left: auto;
  font-size: 1.9rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.grabando {
  font-size: 0.7rem;
  color: #94a3b8;
}
</style>
