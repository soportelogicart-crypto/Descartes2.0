<script setup lang="ts">
import { computed } from 'vue'
import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import {
  datosPreviewPorTipo,
  getByPath,
  type DocumentoPreviewDatos,
} from '@/config/documentos-plantillas/preview-datos'
import { lineasComprobanteTarjeta } from '@/composables/comprobanteTarjeta'
import {
  lineasCabeceraEmpresa,
  lineasMetaTicket,
  lineasTablaArticulos,
  lineasTituloTicket,
  lineasTotalesTicket,
  valorCodigoBarrasTicket,
} from '@/config/documentos-plantillas/ticket-texto'

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

function lineasArticulos(): string[] {
  return lineasTablaArticulos(datos.value.lineas)
}

function lineasComprobante(): string[] {
  const t = datos.value.tarjeta
  return t ? lineasComprobanteTarjeta(t) : []
}

function srcEmblema(): string {
  return str('empresa.emblemaUrl')
}
</script>

<template>
  <div class="ticket-preview">
    <p class="hint">
      Vista previa 80 mm. El logo sale de la carpeta <code>logos</code> de la empresa (bloque
      Emblema). El comprobante de tarjeta solo se imprime tras un cobro o devolución con datáfono.
    </p>

    <div class="desk">
      <div class="paper">
        <div v-for="b in bloques" :key="b.id" class="block" :class="`t-${b.type}`">
          <template v-if="b.type === 'emblema'">
            <div class="center">
              <img v-if="srcEmblema()" class="emblema" :src="srcEmblema()" alt="Logo" />
              <span v-else class="muted small">(sin logo)</span>
            </div>
          </template>

          <template v-else-if="b.type === 'empresa-cabecera'">
            <div v-for="(linea, i) in lineasCabeceraEmpresa(datos)" :key="i" class="mono">{{ linea }}</div>
          </template>

          <template v-else-if="b.type === 'titulo-documento'">
            <div v-for="(linea, i) in lineasTituloTicket(datos, b.label)" :key="i" class="mono">
              {{ linea }}
            </div>
          </template>

          <template v-else-if="b.type === 'bloque-meta'">
            <div v-for="(linea, i) in lineasMetaTicket(datos)" :key="i" class="mono">{{ linea }}</div>
          </template>

          <template v-else-if="b.type === 'tabla-lineas'">
            <div class="sep small">--------------------------------------------------------</div>
            <div v-for="(linea, i) in lineasArticulos()" :key="i" class="mono">{{ linea }}</div>
          </template>

          <template v-else-if="b.type === 'totales-ticket' || b.type === 'totales-iva'">
            <div class="sep small">--------------------------------------------------------</div>
            <div
              v-for="(t, i) in lineasTotalesTicket(datos)"
              :key="i"
              class="mono"
              :class="t.tipo === 'doble' ? 'tot-doble' : 'tot-grande'"
            >{{ t.texto }}</div>
          </template>

          <template v-else-if="b.type === 'literales-puesto'">
            <div v-for="(lit, i) in literales()" :key="i" class="center small lit">{{ lit }}</div>
            <div v-if="!literales().length" class="muted center small">(sin literales)</div>
          </template>

          <template v-else-if="b.type === 'codigo-barras'">
            <div class="center barcode-placeholder">||||| Code 128 |||||</div>
            <div class="center small">{{ valorCodigoBarrasTicket(datos) }}</div>
          </template>

          <template v-else-if="b.type === 'comprobante-tarjeta'">
            <template v-if="datos.tarjeta">
              <div class="sep small">--------------------------------</div>
              <div v-for="(linea, i) in lineasComprobante()" :key="i" class="mono">{{ linea }}</div>
            </template>
            <div v-else class="muted center small">(solo con cobro datáfono)</div>
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
  box-sizing: border-box;
  overflow: hidden;
  max-width: 100%;
  background: #fff;
  padding: 3mm 2.5mm 6mm;
  box-shadow: 0 4px 16px rgb(15 23 42 / 25%);
  font-family: 'Consolas', 'Courier New', monospace;
  font-size: 9px;
  line-height: 1.2;
  color: #0f172a;
}

.emblema {
  max-width: 42mm;
  max-height: 18mm;
  object-fit: contain;
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
  font-size: 9px;
}

.mono {
  white-space: pre;
  font-size: 8.5px;
  line-height: 1.15;
}

.muted {
  color: #94a3b8;
}

/* Font A (42 col) en el mismo ancho de papel que las 56 col; TOTAL a doble alto. */
.mono.tot-grande,
.mono.tot-doble {
  font-size: 9.9px;
  font-weight: 700;
}

.mono.tot-doble {
  transform: scaleY(2);
  transform-origin: top;
  margin-bottom: 1.2em;
}

.linea {
  margin-bottom: 0.25rem;
  border-bottom: 1px dotted #e2e8f0;
  padding-bottom: 0.15rem;
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

.barcode-placeholder {
  letter-spacing: 2px;
  font-size: 10px;
  color: #334155;
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
