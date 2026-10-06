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
  /** `default` si este PC no está unido a una instalación concreta. */
  instalacionId?: string
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

let estadoCache: InstalacionEstado | null = null
let estadoPendiente: Promise<InstalacionEstado> | null = null
let estadoTicket = 0

/** La siguiente navegación vuelve a preguntar a la API. */
export function olvidarInstalacionEstado() {
  estadoCache = null
  estadoTicket += 1
}

function recordarInstalacionEstado(estado: InstalacionEstado): InstalacionEstado {
  estadoTicket += 1
  estadoCache = estado
  return estado
}

/**
 * Estado de instalación. Se reutiliza en memoria hasta recargar, guardar la
 * configuración o llamar a olvidarInstalacionEstado().
 */
export async function getInstalacionEstado(opciones?: {
  refrescar?: boolean
}): Promise<InstalacionEstado> {
  if (!opciones?.refrescar) {
    if (estadoCache) return estadoCache
    if (estadoPendiente) return estadoPendiente
  }

  const ticket = ++estadoTicket
  const peticion = api
    .get<InstalacionEstado>('/api/instalacion/estado')
    .then(({ data }) => {
      if (ticket === estadoTicket) estadoCache = data
      return data
    })
    .finally(() => {
      if (estadoPendiente === peticion) estadoPendiente = null
    })
  estadoPendiente = peticion
  return peticion
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
  if (data?.ok) olvidarInstalacionEstado()
  return data
}

export async function configurarInstalacion(form: InstalacionForm): Promise<InstalacionEstado> {
  const { data } = await api.post<InstalacionEstado>('/api/instalacion/configurar', payloadDesdeForm(form))
  return recordarInstalacionEstado(data)
}

export async function crearInstalacionCliente(
  id: string,
  form: InstalacionForm
): Promise<{ id: string; clave: string; server: string; database: string }> {
  const { data } = await api.post('/api/instalacion/clientes', {
    id,
    ...payloadDesdeForm(form),
  })
  return data
}

/** La clave anterior deja de valer: los demás PC unidos necesitan la nueva. */
export async function regenerarClaveInstalacion(
  id: string
): Promise<{ id: string; clave: string }> {
  const { data } = await api.post(`/api/instalacion/clientes/${encodeURIComponent(id)}/clave`)
  return data
}

export async function migrarInstalacionActiva(): Promise<InstalacionEstado> {
  const { data } = await api.post<InstalacionEstado>('/api/instalacion/migrar')
  return recordarInstalacionEstado(data)
}
