<script setup lang="ts">
import type { TpvTicketEspera } from '@/types/tpv'

defineProps<{
  open: boolean
  tickets: TpvTicketEspera[]
  cargando?: boolean
}>()

const emit = defineEmits<{
  recuperar: [TpvTicketEspera]
  cerrar: []
}>()

function fecha(value: string | null): string {
  if (!value) return ''
  const d = new Date(value.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? value
    : d.toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' })
}

function euros(value: number): string {
  return Number(value || 0).toLocaleString('es-ES', {
    style: 'currency',
    currency: 'EUR',
  })
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @mousedown.prevent>
      <section class="ventana" role="dialog" aria-modal="true">
        <header class="barra">TICKETS EN ESPERA</header>

        <div class="cuerpo">
          <p v-if="cargando" class="estado">Cargando tickets…</p>
          <p v-else-if="!tickets.length" class="estado">No hay tickets en espera en este puesto.</p>
          <template v-else>
            <button
              v-for="ticket in tickets"
              :key="`${ticket.empresa}-${ticket.tipo}-${ticket.albaran}`"
              type="button"
              class="ticket"
              @click="emit('recuperar', ticket)"
            >
              <span class="numero">Ticket {{ ticket.albaran }}</span>
              <span class="importe">{{ euros(ticket.importe) }}</span>
              <span class="cliente">
                {{ ticket.razonSocial || ticket.cliente || 'Sin cliente identificado' }}
              </span>
              <span class="detalle">{{ fecha(ticket.fecha) }} · {{ ticket.lineas }} líneas</span>
            </button>
          </template>
        </div>

        <footer class="pie">
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

.ventana {
  width: min(36rem, 94vw);
  max-height: 92vh;
  overflow: hidden;
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
  font-size: 0.85rem;
  font-weight: 600;
}

.cuerpo {
  display: grid;
  gap: 6px;
  max-height: 66vh;
  padding: 0.75rem;
  overflow: auto;
}

.estado {
  margin: 0;
  padding: 1.5rem 0.75rem;
  color: #64748b;
  text-align: center;
}

.ticket {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.15rem 0.75rem;
  padding: 0.65rem 0.75rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.12s ease, border-color 0.12s ease;
}

.ticket:hover {
  background: #eff6ff;
  border-color: #93c5fd;
}

.numero,
.importe {
  font-size: 0.9rem;
  font-weight: 700;
}

.importe {
  color: #047857;
}

.cliente {
  overflow: hidden;
  font-size: 0.8rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detalle {
  color: #64748b;
  font-size: 0.72rem;
}

.pie {
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.cerrar {
  width: 100%;
  min-height: 2.9rem;
  background: #e2e8f0;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #334155;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
</style>
