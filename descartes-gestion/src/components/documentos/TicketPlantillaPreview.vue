<script setup lang="ts">
import { computed } from 'vue'
import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import {
  datosPreviewPorTipo,
  formatImporte,
  getByPath,
  type DocumentoPreviewDatos,
} from '@/config/documentos-plantillas/preview-datos'

const props = defineProps<{
  plantilla: DocumentoPlantilla
}>()

const datos = computed<DocumentoPreviewDatos>(() => datosPreviewPorTipo(props.plantilla.tipo))

const bloques = computed(() =>
  [...props.plantilla.blocks].sort((a, b) => a.y - b.y || a.x - b.x)
)

function str(path: string): string {
  const v = getByPath(datos.value, path)
  if (v == null) return ''
  return String(v)
}

function literales(): string[] {
  const list = datos.value.puesto?.literales
  return Array.isArray(list) ? list.filter((s) => String(s).trim() !== '') : []
}
</script>

<template>
  <div class="ticket-preview">
    <p class="hint">
      Vista previa 80 mm. Literales de ejemplo = {{ datos.tienda.literalTicket }} líneas (como en
      Tiendas → Literales ticket). Pies Fra./Presupuesto/Vale se usan en documentos A4, no en el
      ticket.
    </p>

    <div class="desk">
      <div class="paper">
        <div v-for="b in bloques" :key="b.id" class="block" :class="`t-${b.type}`">
          <template v-if="b.type === 'empresa-cabecera'">
            <div class="center strong">{{ str('empresa.nombre') }}</div>
            <div class="center small">{{ str('empresa.direccion') }}</div>
            <div class="center small">
              {{ str('empresa.cp') }} {{ str('empresa.poblacion') }}
            </div>
            <div class="center small">Tel. {{ str('empresa.telefono') }} · NIF {{ str('empresa.nif') }}</div>
          </template>

          <template v-else-if="b.type === 'titulo-documento'">
            <div class="center strong">{{ b.label || 'TICKET' }}</div>
            <div class="center">{{ str('documento.numero') }}</div>
          </template>

          <template v-else-if="b.type === 'bloque-meta'">
            <div class="meta">
              <span>{{ str('documento.fecha') }}</span>
              <span>{{ str('documento.terminalSesion') }}</span>
            </div>
            <div v-if="str('documento.atendidoPor')" class="small">
              Atendido: {{ str('documento.atendidoPor') }}
            </div>
          </template>

          <template v-else-if="b.type === 'tabla-lineas'">
            <div v-for="(ln, i) in datos.lineas" :key="i" class="linea">
              <div class="ln-desc">{{ ln.descripcion }}</div>
              <div class="ln-row">
                <span>{{ ln.unidades }} × {{ formatImporte(ln.precio ?? ln.pvp ?? ln.importe) }}</span>
                <span>{{ formatImporte(ln.importe) }}</span>
              </div>
            </div>
          </template>

          <template v-else-if="b.type === 'totales-ticket' || b.type === 'totales-iva'">
            <div v-for="(iva, i) in datos.totales.ivas" :key="i" class="tot-row small">
              <span>Base {{ iva.pje }}%</span>
              <span>{{ formatImporte(iva.base) }}</span>
            </div>
            <div v-for="(iva, i) in datos.totales.ivas" :key="'c' + i" class="tot-row small">
              <span>IVA {{ iva.pje }}%</span>
              <span>{{ formatImporte(iva.cuota) }}</span>
            </div>
            <div class="tot-row strong">
              <span>TOTAL</span>
              <span>{{ formatImporte(datos.totales.importe) }}</span>
            </div>
          </template>

          <template v-else-if="b.type === 'literales-puesto'">
            <div v-for="(lit, i) in literales()" :key="i" class="center small lit">{{ lit }}</div>
            <div v-if="!literales().length" class="muted center small">(sin literales)</div>
          </template>

          <template v-else-if="b.type === 'separador'">
            <div class="sep">{{ b.label || '--------------------------------' }}</div>
          </template>

          <template v-else-if="b.type === 'texto'">
            <div class="center">{{ b.label }}</div>
          </template>

          <template v-else-if="b.type === 'campo'">
            <div class="small">{{ b.label }}: {{ str((b.bind && b.bind[0]) || '') }}</div>
          </template>

          <template v-else>
            <span class="muted">{{ b.label || b.type }}</span>
          </template>
        </div>
      </div>
    </div>

    <aside class="ref-tienda">
      <h4>Textos de la tienda (referencia)</h4>
      <p><strong>Pie Fra. diferida:</strong> {{ datos.tienda.literalFacturaDiferida || '—' }}</p>
      <p><strong>Pie Fra. contado:</strong> {{ datos.tienda.literalFacturaContado || '—' }}</p>
      <p><strong>Pie presupuesto:</strong> {{ datos.tienda.literalPresupuesto || '—' }}</p>
      <p><strong>Pie vale:</strong> {{ datos.tienda.literalVale || '—' }}</p>
      <p>
        <strong>Literales ticket (nº):</strong> {{ datos.tienda.literalTicket }} → se imprimen del
        puesto
      </p>
    </aside>
  </div>
</template>

<style scoped>
.ticket-preview {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(12rem, 16rem);
  gap: 0.75rem;
  align-items: start;
}

.hint {
  grid-column: 1 / -1;
  margin: 0;
  font-size: 0.75rem;
  color: #64748b;
}

.desk {
  display: flex;
  justify-content: center;
  padding: 1rem;
  background: linear-gradient(180deg, #c5ccd6, #b0b8c4);
  border: 1px solid #9aa3af;
  border-radius: 8px;
  min-height: 22rem;
}

.paper {
  width: 80mm;
  max-width: 100%;
  background: #fff;
  padding: 3mm 2.5mm 6mm;
  box-shadow: 0 4px 16px rgb(15 23 42 / 25%);
  font-family: 'Consolas', 'Courier New', monospace;
  font-size: 11px;
  line-height: 1.25;
  color: #0f172a;
}

.block {
  margin-bottom: 0.35rem;
}

.center {
  text-align: center;
}

.strong {
  font-weight: 700;
}

.small {
  font-size: 10px;
}

.muted {
  color: #94a3b8;
}

.meta {
  display: flex;
  justify-content: space-between;
  gap: 0.35rem;
  font-size: 10px;
}

.linea {
  margin-bottom: 0.25rem;
  border-bottom: 1px dotted #e2e8f0;
  padding-bottom: 0.15rem;
}

.ln-desc {
  font-weight: 600;
}

.ln-row,
.tot-row {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
}

.sep {
  text-align: center;
  letter-spacing: -0.5px;
  color: #64748b;
  overflow: hidden;
}

.lit {
  margin: 0.1rem 0;
}

.ref-tienda {
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #f8fafc;
  padding: 0.55rem 0.65rem;
  font-size: 0.72rem;
  color: #475569;
}

.ref-tienda h4 {
  margin: 0 0 0.4rem;
  font-size: 0.75rem;
  color: #334155;
}

.ref-tienda p {
  margin: 0 0 0.35rem;
  line-height: 1.35;
}

@media (max-width: 800px) {
  .ticket-preview {
    grid-template-columns: 1fr;
  }
}
</style>
