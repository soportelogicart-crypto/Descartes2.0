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
  printHtml: (payload: {
    html: string
    impresora?: string
    impresoraId?: number
    silent?: boolean
  }) => Promise<{ ok: boolean; stub?: boolean; impresora?: string; message?: string }>
  printLabel: (payload: unknown) => Promise<{ ok: boolean; stub?: boolean; message?: string }>
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
