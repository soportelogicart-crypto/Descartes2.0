import { getDescartesBridge } from '@/bridge/electron'

const KEY_ID = 'descartes.clienteId'
const KEY_CLAVE = 'descartes.clienteClave'

export type VinculoInstalacion = { id: string; clave: string }

let cache: VinculoInstalacion | null = null

export function vinculoEnMemoria(): VinculoInstalacion | null {
  return cache
}

function guardarEnNavegador(vinculo: VinculoInstalacion | null) {
  if (!vinculo) {
    localStorage.removeItem(KEY_ID)
    localStorage.removeItem(KEY_CLAVE)
    return
  }
  localStorage.setItem(KEY_ID, vinculo.id)
  localStorage.setItem(KEY_CLAVE, vinculo.clave)
}

function leerNavegador(): VinculoInstalacion | null {
  const id = localStorage.getItem(KEY_ID)?.trim().toLowerCase() ?? ''
  const clave = localStorage.getItem(KEY_CLAVE)?.trim() ?? ''
  return id && clave ? { id, clave } : null
}

/** Lee el vínculo antes de la primera petición. En Electron no deja la clave en localStorage. */
export async function cargarVinculoInstalacion(): Promise<VinculoInstalacion | null> {
  const bridge = getDescartesBridge()
  if (bridge?.getVinculoInstalacion) {
    const data = await bridge.getVinculoInstalacion()
    const id = String(data?.id ?? '').trim().toLowerCase()
    const clave = String(data?.clave ?? '').trim()
    cache = id && clave ? { id, clave } : null
    guardarEnNavegador(null)
    return cache
  }
  cache = leerNavegador()
  return cache
}

export async function guardarVinculoInstalacion(id: string, clave: string): Promise<void> {
  const vinculo = { id: id.trim().toLowerCase(), clave: clave.trim() }
  if (!/^[a-z0-9][a-z0-9-]{0,39}$/.test(vinculo.id) || vinculo.id === 'default') {
    throw new Error('El identificador solo puede tener letras minúsculas, números y guiones')
  }
  if (!vinculo.clave) {
    throw new Error('Indique la clave de la instalación')
  }
  const bridge = getDescartesBridge()
  if (bridge?.setVinculoInstalacion) {
    await bridge.setVinculoInstalacion(vinculo)
    guardarEnNavegador(null)
  } else {
    guardarEnNavegador(vinculo)
  }
  cache = vinculo
}

export async function quitarVinculoInstalacion(): Promise<void> {
  const bridge = getDescartesBridge()
  if (bridge?.clearVinculoInstalacion) {
    await bridge.clearVinculoInstalacion()
  }
  guardarEnNavegador(null)
  cache = null
}
