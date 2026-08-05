import type { AbcVentasFiltros, AbcVentasResponse } from '@/types/abc-ventas'
import type { LookupEntidad, LookupResultado } from '@/api/lookup'

export type SessionUser = {
  codigo: string
  nombre: string
  usuario: string
}

export type AppConfig = {
  database: {
    server: string
    database: string
    user: string
    password: string
    port?: number
    options?: {
      encrypt?: boolean
      trustServerCertificate?: boolean
    }
  }
  window?: {
    width?: number
    height?: number
    fullscreen?: boolean
  }
}

export type AbcVentasBridge = {
  isElectron: boolean
  platform: string
  login: (usuario: string, password: string) => Promise<SessionUser>
  logout: () => Promise<{ ok: boolean }>
  me: () => Promise<SessionUser | null>
  getConfig: () => Promise<AppConfig>
  saveConfig: (payload: Partial<AppConfig>) => Promise<AppConfig>
  obtenerAbc: (filtros: AbcVentasFiltros) => Promise<AbcVentasResponse>
  testDb: () => Promise<{ ok: boolean; error?: string; result?: unknown }>
  buscarLookup: (payload: {
    entidad: LookupEntidad
    q?: string
    limit?: number
  }) => Promise<LookupResultado>
  saveExport: (payload: {
    defaultPath: string
    data: number[]
    filters: Array<{ name: string; extensions: string[] }>
  }) => Promise<{ ok: boolean; filePath?: string }>
  onMenuConfiguracion: (handler: () => void) => () => void
}

declare global {
  interface Window {
    abcVentas?: AbcVentasBridge
  }
}

export {}
