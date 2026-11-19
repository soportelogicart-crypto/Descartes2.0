import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/client'
import { obtenerVendedorPuesto } from '@/api/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { getDescartesBridge, isElectronShell } from '@/bridge/electron'

const KEY_EQUIPO = 'descartes.equipoId'
const KEY_PUESTO = 'descartes.puestoCodigo'
const KEY_EMPRESA = 'descartes.empresaCodigo'
const KEY_TIENDA_NOMBRE = 'descartes.tiendaNombre'
const KEY_TIENDA_ACTIVA = 'descartes.tiendaActiva'

export type TiendaPuesto = { tienda: string; tiendaNombre: string }

function leer(key: string): string | null {
  const valor = localStorage.getItem(key)
  return valor?.trim() ? valor.trim() : null
}

function normalizarEquipoId(valor: string): string {
  return valor.trim().toUpperCase()
}

export const usePuestoContextoStore = defineStore('puestoContexto', () => {
  const equipoId = ref<string | null>(leer(KEY_EQUIPO))
  const puestoCodigo = ref<string | null>(leer(KEY_PUESTO))
  const empresaCodigo = ref<string | null>(leer(KEY_EMPRESA))
  const tiendaNombre = ref<string | null>(leer(KEY_TIENDA_NOMBRE))
  const tiendas = ref<TiendaPuesto[]>([])
  const vendedorCodigo = ref<string | null>(null)
  const vendedorNombre = ref<string | null>(null)
  const puestoExiste = ref<boolean | null>(null)
  const puestoAviso = ref<string | null>(null)
  const hydrating = ref(false)
  const hydrateError = ref<string | null>(null)
  const enElectron = computed(() => isElectronShell())

  const configurado = computed(() => {
    if (enElectron.value) {
      return !!empresaCodigo.value && !!puestoCodigo.value
    }
    return !!equipoId.value && !!empresaCodigo.value && !!puestoCodigo.value
  })

  function aplicarLocal(id: string, empresa: string, puesto: string) {
    equipoId.value = id
    empresaCodigo.value = empresa
    puestoCodigo.value = puesto
    localStorage.setItem(KEY_EQUIPO, id)
    localStorage.setItem(KEY_EMPRESA, empresa)
    localStorage.setItem(KEY_PUESTO, puesto)
    void cargarVendedorPuesto()
  }

  /** La tienda elegida en la cabecera manda sobre la de arqueo guardada en el equipo. */
  function conservarTiendaElegida() {
    const recordada = leer(KEY_TIENDA_ACTIVA)
    if (!recordada) return
    empresaCodigo.value = recordada
    localStorage.setItem(KEY_EMPRESA, recordada)
  }

  async function cargarVendedorPuesto() {
    const codigo = puestoCodigo.value?.trim() ?? ''
    if (!codigo) {
      vendedorCodigo.value = null
      vendedorNombre.value = null
      puestoExiste.value = null
      puestoAviso.value = 'No hay puesto configurado en este equipo.'
      return
    }
    try {
      const data = await obtenerVendedorPuesto(codigo)
      vendedorCodigo.value = data.vendedor?.trim() || null
      vendedorNombre.value = data.vendedorNombre?.trim() || null
      const existe = data.existe !== false
      puestoExiste.value = existe
      puestoAviso.value = existe
        ? null
        : `El puesto ${codigo} no existe. Créelo en Mantenimiento → Puestos o reconfigure este equipo.`
    } catch {
      vendedorCodigo.value = null
      vendedorNombre.value = null
      puestoExiste.value = null
      puestoAviso.value = `No se pudo comprobar el puesto ${codigo}.`
    }
  }

  async function hydrateFromElectron(): Promise<boolean> {
    const bridge = getDescartesBridge()
    if (!bridge) return false
    hydrating.value = true
    try {
      const data = await bridge.getEquipoConfig()
      equipoId.value = data.equipoId
      empresaCodigo.value = data.empresaCodigo
      puestoCodigo.value = data.puestoCodigo
      if (data.configurado && data.empresaCodigo && data.puestoCodigo) {
        localStorage.setItem(KEY_EQUIPO, data.equipoId)
        localStorage.setItem(KEY_EMPRESA, data.empresaCodigo)
        localStorage.setItem(KEY_PUESTO, data.puestoCodigo)
      }
      conservarTiendaElegida()
      hydrateError.value = null
      return !!data.configurado
    } catch (e: unknown) {
      hydrateError.value = e instanceof Error ? e.message : 'No se pudo leer la config local del PC'
      return false
    } finally {
      hydrating.value = false
      void cargarVendedorPuesto()
      void cargarTiendasPuesto()
    }
  }

  async function obtenerDesdeServidor(
    id: string
  ): Promise<{ equipoId: string; empresaCodigo: string; puestoCodigo: string } | null> {
    const limpio = normalizarEquipoId(id)
    if (!limpio) return null
    try {
      const { data } = await api.get(`/api/mantenimiento/config-equipo/${encodeURIComponent(limpio)}`)
      return {
        equipoId: String(data.equipoId ?? limpio).trim(),
        empresaCodigo: String(data.empresaCodigo ?? '').trim(),
        puestoCodigo: String(data.puestoCodigo ?? '').trim(),
      }
    } catch (e: unknown) {
      const status = (e as { response?: { status?: number } }).response?.status
      if (status === 404) {
        hydrateError.value = null
        return null
      }
      hydrateError.value = extractApiError(e, 'No se pudo cargar la configuracion del equipo')
      return null
    }
  }

  async function cargarDesdeServidor(id: string): Promise<boolean> {
    const data = await obtenerDesdeServidor(id)
    if (!data) return false
    aplicarLocal(data.equipoId, data.empresaCodigo, data.puestoCodigo)
    conservarTiendaElegida()
    hydrateError.value = null
    return true
  }

  async function hydrateFromApi(): Promise<boolean> {
    if (isElectronShell()) {
      return hydrateFromElectron()
    }
    if (!equipoId.value) return false
    hydrating.value = true
    try {
      return await cargarDesdeServidor(equipoId.value)
    } finally {
      hydrating.value = false
      void cargarTiendasPuesto()
    }
  }

  async function setEquipo(id: string, empresa: string, puesto: string) {
    const empresaLimpia = empresa.trim()
    const puestoLimpio = puesto.trim()
    if (!empresaLimpia || !puestoLimpio) {
      throw new Error('Empresa y puesto son obligatorios')
    }

    const bridge = getDescartesBridge()
    if (bridge) {
      const hostname = (await bridge.getHostname()) || normalizarEquipoId(id) || 'EQUIPO'
      const data = await bridge.setEquipoConfig({
        equipoId: hostname,
        empresaCodigo: empresaLimpia,
        puestoCodigo: puestoLimpio,
      })
      aplicarLocal(data.equipoId, data.empresaCodigo!, data.puestoCodigo!)
      localStorage.setItem(KEY_TIENDA_ACTIVA, empresaLimpia)
      void cargarTiendasPuesto()
      // Espejo opcional en BD para administracion centralizada
      try {
        await api.put(`/api/mantenimiento/config-equipo/${encodeURIComponent(data.equipoId)}`, {
          empresaCodigo: empresaLimpia,
          puestoCodigo: puestoLimpio,
        })
      } catch {
        // La fuente de verdad en caja es el fichero local
      }
      return
    }

    const limpio = normalizarEquipoId(id)
    if (!limpio) {
      throw new Error('Identificador, empresa y puesto son obligatorios')
    }

    const { data } = await api.put(`/api/mantenimiento/config-equipo/${encodeURIComponent(limpio)}`, {
      empresaCodigo: empresaLimpia,
      puestoCodigo: puestoLimpio,
    })

    aplicarLocal(
      String(data.equipoId ?? limpio).trim(),
      String(data.empresaCodigo ?? empresaLimpia).trim(),
      String(data.puestoCodigo ?? puestoLimpio).trim()
    )
    localStorage.setItem(KEY_TIENDA_ACTIVA, empresaLimpia)
    void cargarTiendasPuesto()
  }

  function recordarTienda(nombre: string) {
    const limpio = nombre.trim()
    tiendaNombre.value = limpio || null
    if (limpio) localStorage.setItem(KEY_TIENDA_NOMBRE, limpio)
    else localStorage.removeItem(KEY_TIENDA_NOMBRE)
  }

  /** Tienda de arqueo del puesto y las otras marcadas en el usuario. No cambia el puesto del PC. */
  async function cargarTiendasPuesto() {
    const puesto = puestoCodigo.value?.trim() ?? ''
    if (!puesto) {
      tiendas.value = []
      return
    }
    try {
      const { data } = await api.get('/api/auth/tiendas-puesto', { params: { puesto } })
      const items = ((data.items ?? []) as TiendaPuesto[])
        .map((item) => ({
          tienda: String(item.tienda ?? '').trim(),
          tiendaNombre: String(item.tiendaNombre ?? '').trim(),
        }))
        .filter((item) => item.tienda !== '')
      tiendas.value = items
      const codigos = new Set(items.map((item) => item.tienda))
      const recordada = leer(KEY_TIENDA_ACTIVA)
      const actual = empresaCodigo.value?.trim() ?? ''
      const codigo =
        (recordada && codigos.has(recordada) ? recordada : '') ||
        (actual && codigos.has(actual) ? actual : '') ||
        items[0]?.tienda ||
        ''
      const elegida = items.find((item) => item.tienda === codigo)
      if (elegida) elegirTienda(elegida.tienda, elegida.tiendaNombre || elegida.tienda)
    } catch {
      // Se queda la tienda recordada en este PC.
    }
  }

  function elegirTienda(codigo: string, nombre: string) {
    const limpio = codigo.trim()
    if (!limpio || !puestoCodigo.value) return
    empresaCodigo.value = limpio
    localStorage.setItem(KEY_EMPRESA, limpio)
    localStorage.setItem(KEY_TIENDA_ACTIVA, limpio)
    recordarTienda(nombre || limpio)
  }

  function clearEquipo() {
    equipoId.value = null
    empresaCodigo.value = null
    puestoCodigo.value = null
    vendedorCodigo.value = null
    vendedorNombre.value = null
    puestoExiste.value = null
    puestoAviso.value = null
    tiendaNombre.value = null
    localStorage.removeItem(KEY_EQUIPO)
    localStorage.removeItem(KEY_EMPRESA)
    localStorage.removeItem(KEY_PUESTO)
    localStorage.removeItem(KEY_TIENDA_NOMBRE)
    localStorage.removeItem(KEY_TIENDA_ACTIVA)
    tiendas.value = []
    const bridge = getDescartesBridge()
    if (bridge) {
      void bridge.clearEquipoConfig()
    }
  }

  return {
    equipoId,
    puestoCodigo,
    empresaCodigo,
    tiendaNombre,
    tiendas,
    vendedorCodigo,
    vendedorNombre,
    puestoExiste,
    puestoAviso,
    configurado,
    hydrating,
    hydrateError,
    enElectron,
    hydrateFromApi,
    hydrateFromElectron,
    obtenerDesdeServidor,
    cargarDesdeServidor,
    cargarVendedorPuesto,
    setEquipo,
    recordarTienda,
    cargarTiendasPuesto,
    elegirTienda,
    clearEquipo,
  }
})
