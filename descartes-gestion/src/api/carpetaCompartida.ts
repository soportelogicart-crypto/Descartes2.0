import { api } from './client'

/** Ruta UNC de la carpeta compartida de la instalación. Vacía si no hay ninguna. */
export async function obtenerRutaCarpetaCompartida(): Promise<string> {
  const { data } = await api.get<{ ruta: string }>('/api/mantenimiento/carpeta-compartida')
  return String(data.ruta ?? '')
}

export async function guardarRutaCarpetaCompartida(ruta: string): Promise<string> {
  const { data } = await api.put<{ ruta: string }>('/api/mantenimiento/carpeta-compartida', {
    ruta: ruta.trim(),
  })
  return String(data.ruta ?? '')
}
