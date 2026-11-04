import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { MODULO_PADRE } from '@/config/modulo-padre'
import { modoLocal } from '@/offline/estadoConexion'

/** Sin conexión solo funciona el TPV y la consulta de los catálogos copiados. */
const MODULOS_SIN_CONEXION = new Set(['tpv'])
/** Solo lectura: estos catálogos sí están en la copia local. */
const CONSULTA_LOCAL = new Set([
  'articulos',
  'clientes',
  'familias',
  'macrofamilias',
  'subfamilias',
  'agrupaciones',
  'secciones',
  'subsecciones',
  'impuestos',
  'proveedores',
])
const consultaRegistrada = new Set<string>()

export function usePermisos() {
  const auth = useAuthStore()
  const { permisos } = storeToRefs(auth)

  function puede(modulo: string, accion: 'ver' | 'crear' | 'editar' | 'eliminar'): boolean {
    if (modoLocal() && accion === 'ver' && CONSULTA_LOCAL.has(modulo)) {
      const mapa = permisos.value
      const propio = mapa[modulo]
      const padreId = MODULO_PADRE[modulo]
      const delPadre = padreId ? mapa[padreId] : undefined
      const tpv = mapa.tpv
      const ok = Boolean(
        propio?.ver || propio?.editar || delPadre?.ver || delPadre?.editar || tpv?.ver || tpv?.editar
      )
      if (!consultaRegistrada.has(modulo)) {
        consultaRegistrada.add(modulo)
        // #region agent log
        fetch('http://127.0.0.1:7584/ingest/0f1f3667-dd3e-47f6-820d-e79b40345fcc',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'0d3608'},body:JSON.stringify({sessionId:'0d3608',location:'usePermisos.ts:puede',message:'consulta local',data:{modulo,ok,tienePropio:!!propio,verPropio:!!propio?.ver,tienePadre:!!delPadre,verTpv:!!tpv?.ver},timestamp:Date.now(),hypothesisId:'C'})}).catch(()=>{});
        // #endregion
      }
      return ok
    }
    if (
      modoLocal() &&
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
