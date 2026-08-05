import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { MODULO_PADRE } from '@/config/modulo-padre'

export function usePermisos() {
  const auth = useAuthStore()
  const { permisos } = storeToRefs(auth)

  function puede(modulo: string, accion: 'ver' | 'crear' | 'editar' | 'eliminar'): boolean {
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
