/** Protocolos legacy `TefCentro`. */
export const DATAFONO_CENTROS = [
  { value: 0, label: '0 · BS', driver: 'BS' },
  { value: 1, label: '1 · 4B', driver: '4B' },
  { value: 2, label: '2 · BS EMV', driver: 'BSEMV' },
  { value: 3, label: '3 · DRS', driver: 'DRS' },
  { value: 4, label: '4 · Clear One', driver: 'CLEARONE' },
  { value: 6, label: '6 · Sermepa / Redsys TPV-PC', driver: 'SERMEPA' },
] as const

export type DatafonoLocalConfig = {
  activo: boolean
  proveedor: string
  marca: string
  modelo: string
  driver: string
  tipo: 'webservice' | 'dll'
  /** Legacy TefCentro. */
  centro: number
  /** Legacy TefDemo. */
  demo: boolean
  /** Legacy TefComercio. */
  comercio: string
  /** Clave TPV: vacía al leer (solo se envía al cambiarla). */
  clave: string
  claveConfigurada: boolean
  /** Legacy TefVersion. */
  version: string
  /** Legacy TefTerminal. */
  terminal: string
  /** Legacy TefPuerto (COM o USB). */
  puerto: string
  dllPath: string
  timeoutMs: number
}

export type DescartesEquipoConfig = {
  equipoId: string
  empresaCodigo: string | null
  puestoCodigo: string | null
  datafono: DatafonoLocalConfig
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
  /** A4 apaisado. El Electron instalado anterior a 0.1.4 lo ignora. */
  landscape?: boolean
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

export type PaymentTerminalPayload = {
  driver?: string | null
  terminal?: string | number | null
  operationId: string
  amountCents?: number
  currency?: string
  reference?: string
  timeoutMs?: number
  /** PAGO o DEVOLUCION (Redsys TPV-PC). */
  tipoOperacion?: string
  pedidoOriginal?: string
  rtsOriginal?: string
  codigoAutorizacion?: string
  clr?: string
  factura?: string
  devolucionSinOriginal?: boolean
  devolucionPinpad?: boolean
  modoLegacyRts?: boolean
  configuracion?: DatafonoLocalConfig
}

export type PaymentTerminalResult = {
  ok: boolean
  approved?: boolean
  cancelled?: boolean
  available?: boolean
  operationId?: string | null
  driver?: string
  authorization?: string | null
  reference?: string | null
  code?: string
  message?: string
  /** XML completo de Redsys (ResultOper). */
  receipt?: string | null
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
  setDatafonoConfig: (payload: DatafonoLocalConfig) => Promise<DescartesEquipoConfig>
  clearEquipoConfig: () => Promise<DescartesEquipoConfig>
  /** Identificador y clave de la instalación de este PC. Vacío si usa la de por defecto. */
  getVinculoInstalacion?: () => Promise<{ id: string; clave: string }>
  setVinculoInstalacion?: (payload: { id: string; clave: string }) => Promise<{ id: string; unido: boolean }>
  clearVinculoInstalacion?: () => Promise<{ id: string; clave: string; unido: boolean }>
  getHostname: () => Promise<string>
  /** Logo de la tienda desde la carpeta local `logos`. Vacío si no hay fichero. */
  logoEmpresa: (codigo: string) => Promise<{
    ok: boolean
    dataUrl?: string
    ruta?: string
    message?: string
  }>
  /** Elige imagen en disco y la guarda como `{codigo}.png|jpg…` en la carpeta logos. */
  guardarLogoEmpresa: (codigo: string) => Promise<{
    ok: boolean
    cancelado?: boolean
    dataUrl?: string
    ruta?: string
    carpeta?: string
    message?: string
  }>
  abrirCarpetaLogos: () => Promise<{ ok: boolean; carpeta?: string; message?: string }>
  getLogosDir: () => Promise<{ ok: boolean; carpeta?: string; message?: string }>
  listPrinters: () => Promise<{
    ok: boolean
    stub?: boolean
    printers: DescartesPrinter[]
    message?: string
  }>
  printTicket: (payload: unknown) => Promise<{ ok: boolean; stub?: boolean; message?: string }>
  printHtml: (payload: PrintHtmlPayload) => Promise<PrintHtmlResult>
  /** Electron anterior a esta versión no lo tiene. */
  htmlToPdf?: (payload: {
    html: string
    landscape?: boolean
    pageWidthMm?: number
    pageHeightMm?: number
  }) => Promise<{ ok: boolean; pdfBase64?: string; message?: string }>
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
  paymentTerminalStatus: (payload: Partial<PaymentTerminalPayload>) => Promise<PaymentTerminalResult>
  paymentTerminalCharge: (payload: PaymentTerminalPayload) => Promise<PaymentTerminalResult>
  paymentTerminalCancel: (
    payload: Pick<PaymentTerminalPayload, 'driver' | 'terminal' | 'operationId'>
  ) => Promise<PaymentTerminalResult>
  /** Solo en instaladores recientes. */
  reenfocar?: () => void
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
