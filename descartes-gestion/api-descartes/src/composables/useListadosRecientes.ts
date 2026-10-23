import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { listadoPorId } from '@/config/listados-nav'

const STORAGE_KEY = 'descartes.listados.recientes.v1'
const MAX_RECENTES = 8

type RecienteGuardado = { id: string; ts: number }

function claveAlmacenamiento(usuarioCodigo: string, equipoId: string): string {
  return `${STORAGE_KEY}:${usuarioCodigo}:${equipoId}`
}

function leerRaw(key: string): RecienteGuardado[] {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return []
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return []
    return parsed
      .map((x) => ({
        id: String((x as RecienteGuardado).id ?? ''),
        ts: Number((x as RecienteGuardado).ts ?? 0),
      }))
      .filter((x) => x.id && x.ts > 0)
  } catch {
    return []
  }
}

function escribirRaw(key: string, items: RecienteGuardado[]) {
  try {
    localStorage.setItem(key, JSON.stringify(items))
  } catch {
    /* ignore quota */
  }
}

export function useListadosRecientes() {
  const auth = useAuthStore()
  const puesto = usePuestoContextoStore()
  const ids = ref<string[]>([])

  function storageKey(): string | null {
    const u = auth.usuario?.codigo?.trim()
    const eq = (puesto.equipoId ?? puesto.puestoCodigo ?? 'local').trim()
    if (!u) return null
    return claveAlmacenamiento(u.toUpperCase(), eq.toUpperCase())
  }

  function recargar() {
    const key = storageKey()
    if (!key) {
      ids.value = []
      return
    }
    const ordenados = leerRaw(key).sort((a, b) => b.ts - a.ts)
    ids.value = ordenados.slice(0, MAX_RECENTES).map((x) => x.id)
  }

  function registrar(id: string) {
    if (!listadoPorId(id)) return
    const key = storageKey()
    if (!key) return
    const prev = leerRaw(key).filter((x) => x.id !== id)
    prev.unshift({ id, ts: Date.now() })
    escribirRaw(key, prev.slice(0, MAX_RECENTES))
    recargar()
  }

  watch(
    () => [auth.usuario?.codigo, puesto.equipoId, puesto.puestoCodigo] as const,
    () => recargar(),
    { immediate: true }
  )

  return { idsRecientes: ids, registrarReciente: registrar, recargarRecientes: recargar }
}
