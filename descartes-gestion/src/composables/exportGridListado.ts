import type { GridColumn } from '@/config/entidad-grid-columns'
import type { GridOptionsMap } from '@/components/mantenimiento/EntidadGrid.vue'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

export type ListadoGridColumnDef = Pick<GridColumn, 'key' | 'label' | 'type' | 'optionsSource'>

export function filasVisiblesGrid(filas: Record<string, unknown>[]): Record<string, unknown>[] {
  return filas.filter((f) => !(f as { _nuevo?: boolean })._nuevo)
}

function etiquetaSelect(
  codigo: string,
  optionsMap: GridOptionsMap | undefined,
  source: string | undefined
): string {
  if (!codigo || !source || !optionsMap) return codigo
  const opts = optionsMap[source]
  if (!opts?.length) return codigo
  const hit = opts.find((o) => o.value === codigo)
  return hit?.label ?? codigo
}

export function valorCeldaListado(
  fila: Record<string, unknown>,
  col: ListadoGridColumnDef,
  optionsMap?: GridOptionsMap
): string {
  const raw = fila[col.key]
  if (col.type === 'checkbox') {
    return raw ? 'Sí' : 'No'
  }
  if (col.type === 'number') {
    if (raw == null || raw === '') return ''
    const n = Number(raw)
    return Number.isFinite(n) ? numCsv(n) : String(raw)
  }
  if (col.type === 'select' && col.optionsSource) {
    const codigo = String(raw ?? '').trim()
    return etiquetaSelect(codigo, optionsMap, col.optionsSource)
  }
  return String(raw ?? '').trim()
}

export function filasGridATexto(
  columnas: ListadoGridColumnDef[],
  filas: Record<string, unknown>[],
  optionsMap?: GridOptionsMap
): string[][] {
  return filas.map((fila) => columnas.map((col) => valorCeldaListado(fila, col, optionsMap)))
}

export function exportarGridExcel(opciones: {
  nombreArchivo: string
  columnas: ListadoGridColumnDef[]
  filas: Record<string, unknown>[]
  optionsMap?: GridOptionsMap
}): void {
  const filas = filasVisiblesGrid(opciones.filas)
  const cab = opciones.columnas.map((c) => c.label)
  const lines = [cab.map(escCsv).join(';')]
  for (const row of filasGridATexto(opciones.columnas, filas, opciones.optionsMap)) {
    lines.push(row.map(escCsv).join(';'))
  }
  descargarCsv(opciones.nombreArchivo, lines)
}

export async function exportarGridImprimir(opciones: {
  titulo: string
  columnas: ListadoGridColumnDef[]
  filas: Record<string, unknown>[]
  optionsMap?: GridOptionsMap
  metaLineas?: string[]
  slugArchivo?: string
}): Promise<string> {
  const filas = filasVisiblesGrid(opciones.filas)
  const res = await imprimirListadoHtml({
    titulo: opciones.titulo,
    metaLineas: opciones.metaLineas,
    thead: opciones.columnas.map((c) => c.label),
    filas: filasGridATexto(opciones.columnas, filas, opciones.optionsMap),
    pie: [`${filas.length} fila(s)`],
    filenameFallback: `${opciones.slugArchivo ?? 'listado'}.html`,
  })
  return res.message
}

/** Maestros con solo código y descripción en grid. */
export const COLUMNAS_CODIGO_DESCRIPCION: ListadoGridColumnDef[] = [
  { key: 'codigo', label: 'Codigo', type: 'text' },
  { key: 'descripcion', label: 'Descripcion', type: 'text' },
]
