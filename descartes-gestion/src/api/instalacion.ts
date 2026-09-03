import { api } from './client'

export type TipoConexionBd = 'local' | 'nube'

export interface InstalacionDiagnostico {
  servidorConfigurado: string
  baseDatosConfigurada: string
  servidorSql: string
  baseDatos: string
  columnasUsuarios: string[]
  tieneRol: boolean
  tieneBaja: boolean
}

export interface InstalacionEstado {
  configurado: boolean
  primeraVez: boolean
  origen: 'instalacion' | 'env'
  tipo?: TipoConexionBd
  server: string | null
  database: string | null
  user?: string
  conexionOk: boolean
  errorConexion: string | null
  migraciones: {
    aplicadas: string[]
    pendientes: string[]
    total: number
  }
  adminListo: boolean
  esquemaOk?: boolean
  diagnostico?: InstalacionDiagnostico | null
  requiereAccion: boolean
  mensaje?: string
  acceso: {
    usuario: string
    password: string
  }
  admin?: {
    usuario: string
    password: string
    creado: boolean
    actualizado: boolean
  }
}

export interface InstalacionForm {
  tipo: TipoConexionBd
  server: string
  database: string
  user: string
  password: string
  trustCert: boolean
}

export async function getInstalacionEstado(): Promise<InstalacionEstado> {
  const { data } = await api.get<InstalacionEstado>('/api/instalacion/estado')
  return data
}

function payloadDesdeForm(form: InstalacionForm) {
  return {
    tipo: form.tipo,
    server: form.server,
    database: form.database,
    user: form.user,
    password: form.password,
    trust_cert: form.trustCert,
  }
}

export async function probarInstalacion(form: InstalacionForm): Promise<{
  ok: boolean
  mensaje: string
  migraciones?: InstalacionEstado['migraciones']
}> {
  const { data } = await api.post('/api/instalacion/probar', payloadDesdeForm(form), {
    timeout: 45000,
  })
  return data
}

export async function configurarInstalacion(form: InstalacionForm): Promise<InstalacionEstado> {
  const { data } = await api.post<InstalacionEstado>('/api/instalacion/configurar', payloadDesdeForm(form))
  return data
}

export async function migrarInstalacionActiva(): Promise<InstalacionEstado> {
  const { data } = await api.post<InstalacionEstado>('/api/instalacion/migrar')
  return data
}
