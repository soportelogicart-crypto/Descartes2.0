<script setup lang="ts">
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import type { DocumentoPlantilla, PlantillaBloque } from '@/config/documentos-plantillas'
import { esPlantillaEtiqueta, pageSizeMm } from '@/config/documentos-plantillas'
import {
  datosPreviewPorTipo,
  formatImporte,
  getByPath,
  type DocumentoPreviewDatos,
} from '@/config/documentos-plantillas/preview-datos'
import { svgCodigoBarrasBloque } from '@/config/documentos-plantillas/etiqueta-html'

const props = defineProps<{
  plantilla: DocumentoPlantilla
  /** Si se pasa, se usan datos reales (venta) en lugar del ejemplo del diseñador. */
  datos?: DocumentoPreviewDatos | null
}>()

/** ~1 mm en pantalla a 96 dpi (proporción real de un A4 en CSS). */
const FOLIO_PX_PER_MM = 96 / 25.4
const wrapRef = ref<HTMLElement | null>(null)
const pxPerMm = ref(FOLIO_PX_PER_MM)

const esEtiqueta = computed(() => esPlantillaEtiqueta(props.plantilla))
const pageDims = computed(() => pageSizeMm(props.plantilla))
const PAGE_W = computed(() => pageDims.value.widthMm)
const PAGE_H = computed(() => pageDims.value.heightMm)

const datos = computed<DocumentoPreviewDatos>(
  () => props.datos ?? datosPreviewPorTipo(props.plantilla.tipo)
)

const pageStyle = computed(() => ({
  width: `${PAGE_W.value * pxPerMm.value}px`,
  height: `${PAGE_H.value * pxPerMm.value}px`,
}))

function blockStyle(b: PlantillaBloque) {
  return {
    left: `${b.x * pxPerMm.value}px`,
    top: `${b.y * pxPerMm.value}px`,
    width: `${b.w * pxPerMm.value}px`,
    height: `${b.h * pxPerMm.value}px`,
  }
}

/** Tamaño de fuente en px de pantalla a partir de fontSizeMm (impresión real en mm). */
function textStyle(b: PlantillaBloque): Record<string, string> {
  if (b.type !== 'campo' && b.type !== 'texto' && b.type !== 'titulo-documento') return {}
  const mm = Number(b.props?.fontSizeMm)
  const hasMm = Number.isFinite(mm) && mm > 0
  if (!hasMm && !esEtiqueta.value) return {}
  const sizeMm = hasMm ? mm : 3
  const style: Record<string, string> = {
    fontSize: `${Math.max(8, sizeMm * pxPerMm.value)}px`,
    lineHeight: '1.15',
  }
  if (b.props?.fontWeight === 'bold') style.fontWeight = '700'
  if (b.props?.align === 'right') style.textAlign = 'right'
  else if (b.props?.centrado) style.textAlign = 'center'
  return style
}

function str(path: string): string {
  const v = getByPath(datos.value, path)
  if (v == null) return ''
  return String(v)
}

function plantillaTexto(tpl: string): string {
  return tpl.replace(/\{\{\s*([^}]+)\s*\}\}/g, (_, key: string) => str(key.trim()))
}

function qrSvgUrl(payload: string): string {
  // Placeholder visual del QR (no es un QR real; la generación real irá en impresión).
  const size = 80
  const cells = 9
  const cell = size / cells
  let rects = ''
  let h = 0
  for (let i = 0; i < payload.length; i++) h = (h * 31 + payload.charCodeAt(i)) >>> 0
  for (let y = 0; y < cells; y++) {
    for (let x = 0; x < cells; x++) {
      const border = x < 2 || y < 2 || x >= cells - 2 || y >= cells - 2
      const bit = ((h >> ((x + y * cells) % 31)) & 1) === 1
      if (border || bit) {
        rects += `<rect x="${x * cell}" y="${y * cell}" width="${cell}" height="${cell}" fill="#0f172a"/>`
      }
    }
  }
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
    <rect width="${size}" height="${size}" fill="#fff"/>${rects}
  </svg>`
  return 'data:image/svg+xml,' + encodeURIComponent(svg)
}

function fitScale() {
  const el = wrapRef.value
  if (!el) return
  const available = Math.max(200, el.clientWidth - 48)
  const availableH = Math.max(120, el.clientHeight - 24)
  const w = PAGE_W.value || 210
  const h = PAGE_H.value || 30
  if (esEtiqueta.value) {
    // Escala real CSS (~96 dpi): 1 mm ≈ 3.78 px. Solo reducir si no cabe.
    const real = FOLIO_PX_PER_MM
    const fit = Math.min(available / w, availableH / h)
    pxPerMm.value = Math.min(real, Math.max(1.2, fit))
  } else {
    pxPerMm.value = Math.min(FOLIO_PX_PER_MM, Math.max(1.8, available / w))
  }
}

onMounted(() => {
  void nextTick(() => {
    fitScale()
    requestAnimationFrame(() => fitScale())
  })
  window.addEventListener('resize', fitScale)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', fitScale)
})

watch(
  () => props.plantilla.id,
  () => fitScale()
)

watch(
  () => [PAGE_W.value, PAGE_H.value, props.plantilla.tipo],
  () => {
    void nextTick(() => fitScale())
  }
)

const columnasLineas = computed(() => {
  const t = props.plantilla.blocks.find((b) => b.type === 'tabla-lineas')
  return t?.columns ?? []
})

const PIE_DEFAULT =
  '{{empresa.razonSocial}} N.I.F.{{empresa.nif}}  Página {{documento.pagina}}'

function textoPie(b: PlantillaBloque): string {
  const tpl = String(b.props?.plantilla ?? PIE_DEFAULT)
  return plantillaTexto(tpl)
}

function etiquetaTotal(b: PlantillaBloque): string {
  return String(b.props?.etiquetaTotal ?? 'IMPORTE EU')
}

function etiquetaModo(b: PlantillaBloque): string {
  return String(b.props?.etiquetaModo ?? '')
}

function barcodeHtml(b: PlantillaBloque): string {
  const path = (b.bind && b.bind[0]) || (esEtiqueta.value ? 'articulo.ean' : 'documento.codigoBarras')
  const code = str(path) || str('documento.codigoBarras') || str('articulo.ean')
  return svgCodigoBarrasBloque(code, b)
}
</script>

<template>
  <div class="preview">
    <p class="hint">
      <template v-if="esEtiqueta">
        Vista previa etiqueta {{ PAGE_W }}×{{ PAGE_H }} mm a tamaño real en pantalla (~1:1). Datos de ejemplo (artículo).
      </template>
      <template v-else>
        Vista previa a tamaño de folio A4 (proporción de impresión). Datos de ejemplo.
      </template>
    </p>
    <div ref="wrapRef" class="canvas-wrap">
      <div class="folio">
        <div class="page" :style="pageStyle">
        <div
          v-for="b in plantilla.blocks"
          :key="b.id"
          class="block"
          :class="`type-${b.type}`"
          :style="blockStyle(b)"
        >
          <!-- Emblema -->
          <template v-if="b.type === 'emblema'">
            <img class="emblema" :src="str('empresa.emblemaUrl')" alt="Emblema" />
          </template>

          <!-- Empresa -->
          <template v-else-if="b.type === 'empresa-cabecera'">
            <div class="empresa">
              <strong>{{ str('empresa.nombre') }}</strong>
              <span>{{ str('empresa.direccion') }}</span>
              <span>{{ str('empresa.cp') }} {{ str('empresa.poblacion') }}</span>
              <span>{{ str('empresa.provincia') }}</span>
              <span>Tel. {{ str('empresa.telefono') }}</span>
              <span v-if="str('empresa.email')">{{ str('empresa.email') }}</span>
            </div>
          </template>

          <!-- Título -->
          <template v-else-if="b.type === 'titulo-documento'">
            <div class="titulo" :class="{ 'fs-mm': !!textStyle(b).fontSize }" :style="textStyle(b)">
              <strong>{{ b.label || 'DOCUMENTO' }}</strong>
              <span>{{ str('documento.numero') }}</span>
              <em v-if="etiquetaModo(b)">{{ etiquetaModo(b) }}</em>
            </div>
          </template>

          <!-- Meta -->
          <template v-else-if="b.type === 'bloque-meta'">
            <div class="meta">
              <div><span>Fecha</span> {{ str('documento.fecha') }}</div>
              <div v-if="str('documento.suPedido')"><span>Su Pedido</span> {{ str('documento.suPedido') }}</div>
              <div v-if="str('documento.albaran')"><span>Albarán</span> {{ str('documento.albaran') }}</div>
              <div v-if="str('documento.atendidoPor')"><span>Atendido por</span> {{ str('documento.atendidoPor') }}</div>
              <div v-if="str('documento.terminalSesion')"><span>Terminal-Sesión</span> {{ str('documento.terminalSesion') }}</div>
            </div>
          </template>

          <!-- Cliente -->
          <template v-else-if="b.type === 'bloque-cliente'">
            <div class="cliente">
              <div class="cli-cod">Cliente {{ str('cliente.codigo') }}</div>
              <strong>{{ str('cliente.nombre') }}</strong>
              <span>{{ str('cliente.direccion') }}</span>
              <span>{{ str('cliente.cp') }} {{ str('cliente.poblacion') }}</span>
              <span>{{ str('cliente.provincia') }} · {{ str('cliente.pais') }}</span>
              <span>CIF.{{ str('cliente.cif') }}</span>
            </div>
          </template>

          <!-- Texto fijo -->
          <template v-else-if="b.type === 'texto'">
            <div class="campo" :class="{ 'fs-mm': !!textStyle(b).fontSize }" :style="textStyle(b)">
              {{ b.label || 'Texto' }}
            </div>
          </template>

          <!-- Campo genérico -->
          <template v-else-if="b.type === 'campo'">
            <div
              class="campo"
              :class="{
                'campo-right': b.props?.align === 'right',
                'fs-mm': !!textStyle(b).fontSize,
              }"
              :style="textStyle(b)"
            >
              <span v-if="b.label" class="campo-label">{{ b.label }}</span>
              <span v-for="path in b.bind ?? []" :key="path">{{
                b.props?.format === 'importe' ? formatImporte(Number(str(path) || 0)) : str(path)
              }}</span>
            </div>
          </template>

          <!-- Código barras: bind[0] o documento.codigoBarras -->
          <template v-else-if="b.type === 'codigo-barras'">
            <div class="barcode" v-html="barcodeHtml(b)" />
          </template>

          <!-- Tabla líneas -->
          <template v-else-if="b.type === 'tabla-lineas'">
            <table class="lineas">
              <thead>
                <tr>
                  <th v-for="c in columnasLineas" :key="c.key" :style="{ width: c.width + '%' }">
                    {{ c.label }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <template v-for="(lin, i) in datos.lineas" :key="i">
                  <tr v-if="lin.albaranCabecera" class="cab-alb">
                    <td :colspan="columnasLineas.length">{{ lin.albaranCabecera }}</td>
                  </tr>
                  <tr v-else>
                    <td v-for="c in columnasLineas" :key="c.key">
                      <template v-if="c.key === 'unidades' || c.key === 'dto' || c.key === 'pjeIva'">
                        {{ lin[c.key as keyof typeof lin] || '' }}
                      </template>
                      <template v-else-if="c.key === 'precioSinIva' || c.key === 'precio' || c.key === 'importe' || c.key === 'pvp'">
                        {{ formatImporte(Number(lin[c.key as keyof typeof lin] ?? 0)) }}
                      </template>
                      <template v-else>
                        {{ lin[c.key as keyof typeof lin] ?? '' }}
                      </template>
                    </td>
                  </tr>
                  <tr v-if="lin.nota" class="nota">
                    <td :colspan="columnasLineas.length">{{ lin.nota }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </template>

          <!-- Totales -->
          <template v-else-if="b.type === 'totales-iva'">
            <div class="totales">
              <div class="tot-row">
                <span>Base Imponible</span>
                <strong>{{ formatImporte(datos.totales.base) }}</strong>
              </div>
              <div v-for="(iva, i) in datos.totales.ivas" :key="i" class="tot-row">
                <span>IVA {{ iva.pje }}%</span>
                <span>{{ formatImporte(iva.base) }}</span>
                <strong>{{ formatImporte(iva.cuota) }}</strong>
              </div>
              <div class="tot-row total">
                <span>{{ etiquetaTotal(b) }}</span>
                <strong>{{ formatImporte(datos.totales.importe) }}</strong>
              </div>
            </div>
          </template>

          <!-- Banco -->
          <template v-else-if="b.type === 'datos-bancarios'">
            <div class="banco">
              <span>{{ str('empresa.banco') }}</span>
              <span>IBAN {{ str('empresa.iban') }}</span>
              <span>SWIFT {{ str('empresa.swift') }}</span>
            </div>
          </template>

          <!-- Vencimientos -->
          <template v-else-if="b.type === 'vencimientos'">
            <div class="venc">
              <div class="venc-h">Vencimiento · Importe</div>
              <div v-for="(v, i) in datos.vencimientos" :key="i" class="tot-row">
                <span>{{ v.fecha }}</span>
                <strong>{{ formatImporte(v.importe) }}</strong>
              </div>
              <div v-if="!datos.vencimientos.length" class="muted">Sin vencimientos</div>
            </div>
          </template>

          <!-- QR Verifactu -->
          <template v-else-if="b.type === 'qr-verifactu'">
            <div class="qr">
              <img :src="qrSvgUrl(str('verifactu.qrPayload'))" alt="QR Verifactu" />
              <span>Verifactu</span>
            </div>
          </template>

          <!-- Pie -->
          <template v-else-if="b.type === 'pie'">
            <div class="pie">
              {{ textoPie(b) }}
            </div>
          </template>

          <template v-else>
            <span class="fallback">{{ b.label || b.type }}</span>
          </template>
        </div>
      </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.preview {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  min-width: 0;
}

.hint {
  margin: 0;
  font-size: 0.75rem;
  color: #64748b;
}

.canvas-wrap {
  overflow: auto;
  padding: 1.25rem 1rem 1.5rem;
  background:
    radial-gradient(ellipse at 50% 0%, #d8dee8 0%, transparent 55%),
    linear-gradient(180deg, #c5ccd6 0%, #b0b8c4 100%);
  border: 1px solid #9aa3af;
  border-radius: 8px;
  max-height: min(82vh, 1100px);
  min-height: 28rem;
}

.folio {
  display: flex;
  justify-content: center;
  align-items: flex-start;
  min-height: 100%;
}

.page {
  position: relative;
  flex: 0 0 auto;
  background: #fff;
  box-shadow:
    0 1px 0 rgb(255 255 255 / 80%) inset,
    0 0 0 1px rgb(15 23 42 / 8%),
    0 8px 24px rgb(15 23 42 / 22%),
    0 2px 6px rgb(15 23 42 / 12%);
  font-family: 'Segoe UI', system-ui, sans-serif;
  color: #0f172a;
}

.block {
  position: absolute;
  box-sizing: border-box;
  overflow: hidden;
  padding: 0.08rem 0.12rem;
  font-size: 0.65rem;
  line-height: 1.25;
}

.empresa,
.cliente,
.meta,
.campo,
.banco,
.totales,
.venc {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  height: 100%;
}

.campo-right {
  align-items: flex-end;
  text-align: right;
}

.empresa strong,
.cliente strong,
.titulo strong {
  font-size: 0.72rem;
}

.titulo {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.titulo em {
  font-style: normal;
  font-weight: 700;
  color: #1d4ed8;
  font-size: 0.68rem;
}

.meta span,
.campo-label,
.cli-cod {
  color: #64748b;
  font-size: 0.58rem;
}

.fs-mm,
.fs-mm strong,
.fs-mm span,
.fs-mm em,
.fs-mm .campo-label {
  font-size: inherit;
}

.meta div {
  display: flex;
  gap: 0.35rem;
}

.emblema {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}

.barcode {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.barcode :deep(svg) {
  display: block;
  width: 100%;
  height: 100%;
  max-height: 100%;
}

.lineas {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.55rem;
}

.lineas th {
  text-align: left;
  border-bottom: 1px solid #94a3b8;
  padding: 0.05rem 0.1rem;
  font-weight: 600;
  white-space: nowrap;
}

.lineas td {
  padding: 0.05rem 0.1rem;
  vertical-align: top;
  border-bottom: 1px solid #e2e8f0;
}

.lineas .cab-alb td {
  font-weight: 600;
  background: #f8fafc;
  border-bottom: 0;
  padding-top: 0.25rem;
}

.lineas .nota td {
  color: #64748b;
  font-style: italic;
  border-bottom: 0;
}

.tot-row {
  display: flex;
  justify-content: space-between;
  gap: 0.35rem;
}

.tot-row.total {
  margin-top: 0.2rem;
  padding-top: 0.15rem;
  border-top: 1px solid #94a3b8;
  font-size: 0.68rem;
}

.venc-h {
  font-weight: 600;
  margin-bottom: 0.15rem;
}

.qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.15rem;
  height: 100%;
}

.qr img {
  width: 85%;
  height: auto;
  max-height: 78%;
  object-fit: contain;
}

.qr span {
  font-size: 0.55rem;
  color: #6d28d9;
  font-weight: 600;
}

.pie {
  font-size: 0.58rem;
  color: #334155;
  display: flex;
  align-items: center;
  height: 100%;
  border-top: 1px solid #e2e8f0;
}

.muted,
.fallback {
  color: #94a3b8;
}

.banco {
  font-size: 0.58rem;
}
</style>
