export type DescartesEquipoConfig = {
  equipoId: string
  empresaCodigo: string | null
  puestoCodigo: string | null
  configurado: boolean
  actualizado?: string | null
}

export type DescartesPrinter = {
  id: number
  name: string
  displayName?: string
  description?: string
  isDefault?: boolean
  status?: number | null
}

/** Payload A4 (`printHtml`). */
export type PrintHtmlPayload = {
  html: string
  impresora?: string
  impresoraId?: number
  silent?: boolean
}

export type PrintHtmlResult = {
  ok: boolean
  stub?: boolean
  impresora?: string
  message?: string
}

/**
 * Payload etiqueta (`printLabel`, 005 / T019–T020).
 * `pageWidthMm` / `pageHeightMm` = tamaño de la plantilla (mm).
 */
export type PrintLabelPayload = {
  html: string
  impresora?: string
  impresoraId?: number
  silent?: boolean
  /** Ancho página mm (alias `widthMm`). Obligatorio ≥ 5. */
  pageWidthMm?: number
  widthMm?: number
  /** Alto página mm (alias `heightMm`). Obligatorio ≥ 5. */
  pageHeightMm?: number
  heightMm?: number
  /** Copias físicas (1–500). Default 1. */
  copies?: number
  landscape?: boolean
}

export type PrintLabelResult = {
  ok: boolean
  stub?: boolean
  impresora?: string
  pageWidthMm?: number
  pageHeightMm?: number
  copies?: number
  message?: string
}

export type DescartesBridge = {
  isElectron: true
  platform: string
  getEquipoConfig: () => Promise<DescartesEquipoConfig>
  setEquipoConfig: (payload: {
    empresaCodigo: string
    puestoCodigo: string
    equipoId?: string
  }) => Promise<DescartesEquipoConfig>
  clearEquipoConfig: () => Promise<DescartesEquipoConfig>
  getHostname: () => Promise<string>
  listPrinters: () => Promise<{
    ok: boolean
    stub?: boolean
    printers: DescartesPrinter[]
    message?: string
  }>
  printTicket: (payload: unknown) => Promise<{ ok: boolean; stub?: boolean; message?: string }>
  printHtml: (payload: PrintHtmlPayload) => Promise<PrintHtmlResult>
  printLabel: (payload: PrintLabelPayload) => Promise<PrintLabelResult>
  openCashDrawer: () => Promise<{ ok: boolean; stub?: boolean; message?: string }>
  readCashDrawer: (payload?: {
    formaPago?: string
    puesto?: string
  }) => Promise<{
    ok: boolean
    stub?: boolean
    formaPago?: string
    importe?: number
    monedas?: number[]
    message?: string
  }>
  readScale: () => Promise<{ ok: boolean; stub?: boolean; weight: number | null; unit?: string; message?: string }>
  displayPrice: (payload: unknown) => Promise<{ ok: boolean; stub?: boolean; message?: string }>
}

declare global {
  interface Window {
    descartes?: DescartesBridge
  }
}

export function isElectronShell(): boolean {
  return typeof window !== 'undefined' && window.descartes?.isElectron === true
}

export function getDescartesBridge(): DescartesBridge | null {
  return isElectronShell() ? (window.descartes ?? null) : null
}

/**
 * Imprime etiqueta vía Electron. Lanza si no hay bridge o si `ok` es false.
 * Fallback navegador: abre diálogo `window.print()` con el HTML.
 */
export async function imprimirEtiquetaViaBridge(
  payload: PrintLabelPayload
): Promise<PrintLabelResult> {
  const widthMm = Number(payload.pageWidthMm ?? payload.widthMm)
  const heightMm = Number(payload.pageHeightMm ?? payload.heightMm)
  if (!Number.isFinite(widthMm) || widthMm < 5 || !Number.isFinite(heightMm) || heightMm < 5) {
    throw new Error('Tamaño de etiqueta inválido (pageWidthMm / pageHeightMm)')
  }
  if (!String(payload.html ?? '').trim()) {
    throw new Error('html de etiqueta vacío')
  }

  const bridge = getDescartesBridge()
  if (bridge?.printLabel) {
    const res = await bridge.printLabel({
      ...payload,
      pageWidthMm: widthMm,
      pageHeightMm: heightMm,
      copies: payload.copies ?? 1,
      silent: payload.silent ?? Boolean(payload.impresora || payload.impresoraId),
    })
    if (!res.ok) {
      throw new Error(res.message || 'Error al imprimir etiqueta')
    }
    return res
  }

  // Fallback sin Electron: preview + diálogo del sistema.
  const w = window.open('', '_blank', 'width=480,height=640')
  if (!w) {
    throw new Error('Popup bloqueado: permita ventanas emergentes para imprimir')
  }
  w.document.write(payload.html)
  w.document.close()
  w.focus()
  w.print()
  return {
    ok: true,
    stub: true,
    pageWidthMm: widthMm,
    pageHeightMm: heightMm,
    copies: payload.copies ?? 1,
    message: payload.impresora
      ? `Diálogo de impresión abierto (preferida: ${payload.impresora})`
      : 'Diálogo de impresión abierto',
  }
}
