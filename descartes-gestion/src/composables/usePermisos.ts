import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { MODULO_PADRE } from '@/config/modulo-padre'
import { modoLocal } from '@/offline/estadoConexion'

/** Sin conexión solo funciona el TPV (venta en efectivo y consulta de clientes y artículos). */
const MODULOS_SIN_CONEXION = new Set(['tpv'])
/** Solo lectura: estos catálogos sí están en la copia local. */
const CONSULTA_LOCAL = new Set(['articulos', 'clientes', 'familias', 'macrofamilias', 'subfamilias'])

export function usePermisos() {
  const auth = useAuthStore()
  const { permisos } = storeToRefs(auth)

  function puede(modulo: string, accion: 'ver' | 'crear' | 'editar' | 'eliminar'): boolean {
    const consultaLocal = accion === 'ver' && CONSULTA_LOCAL.has(modulo)
    if (
      modoLocal() &&
      !consultaLocal &&
      !MODULOS_SIN_CONEXION.has(modulo) &&
      !MODULOS_SIN_CONEXION.has(MODULO_PADRE[modulo] ?? '')
    ) {
      return false
    }
    const propio = permisos.value[modulo]
    if (propio) {
      return Boolean(propio[accion])
    }
    const padre = MODULO_PADRE[modulo]
    if (!padre) return false
    return Boolean(permisos.value[padre]?.[accion])
  }

  return { puede }
}
