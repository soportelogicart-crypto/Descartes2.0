<script setup lang="ts">
import type { TpvLineaBorrador } from '@/types/tpv'

defineProps<{
  lineas: TpvLineaBorrador[]
  total: string
  seleccion: number
  guardando?: boolean
}>()

const emit = defineEmits<{
  seleccionar: [number]
}>()
</script>

<template>
  <div class="ticket">
    <table class="ticket-grid">
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
  background: #d4d0c8;
}

.ticket-grid {
  flex: 1;
  display: block;
  overflow-y: auto;
  min-height: 0;
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  border: 2px inset #f0f0f0;
  font-family: 'Segoe UI', Tahoma, sans-serif;
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
  background: #d4d0c8;
  border: 1px solid #9a9a9a;
  padding: 0.3rem 0.35rem;
  font-size: 0.78rem;
  font-weight: 700;
  text-align: left;
  color: #000;
}

.ticket-grid td {
  border-bottom: 1px solid #dcdcdc;
  padding: 0.3rem 0.35rem;
  color: #000;
  vertical-align: top;
}

.ticket-grid tbody tr {
  cursor: pointer;
}

.ticket-grid tbody tr.sel td {
  background: #000080;
  color: #fff;
}

.c-cant {
  width: 3.5rem;
  text-align: right;
}

.c-num {
  width: 5rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.c-dto {
  width: 4.4rem;
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
  color: #606060;
}

.sel .cod {
  color: #c8c8dc;
}

.vacia td {
  text-align: center;
  color: #707070;
  font-style: italic;
  cursor: default;
}

.ticket-total {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  margin-top: 4px;
  padding: 0.4rem 0.6rem;
  background: #000080;
  border: 2px outset #f0f0f0;
  color: #fff;
}

.lbl {
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.val {
  margin-left: auto;
  font-size: 1.9rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.grabando {
  font-size: 0.7rem;
  color: #b9c4e6;
}
</style>
