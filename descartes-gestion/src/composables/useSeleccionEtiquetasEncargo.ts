/**
 * Elección de cuántas etiquetas de sobre se imprimen de cada línea de un encargo.
 *
 * Una línea con cantidad 3 lleva 3 sobres; quien quiera una sola etiqueta la baja a 1.
 * Si ninguna línea tiene más de un sobre no hay nada que elegir y no se abre la ventana.
 */
import { ref } from 'vue'
import { api } from '@/api/client'

export type LineaSobreEncargo = {
  nroLin: number
  articulo: string
  producto: string
  cantidad: number
  sobres: number
}

export type SeleccionEtiquetaEncargo = { nroLin: number; cantidad: number }

/**
 * Resultado de pedir la elección:
 * - `undefined`: no hay nada que elegir, se imprime todo como siempre.
 * - `null`: el usuario canceló.
 * - lista: etiquetas elegidas por línea.
 */
export type ResultadoSeleccion = SeleccionEtiquetaEncargo[] | undefined | null

export function useSeleccionEtiquetasEncargo() {
  const lineas = ref<LineaSobreEncargo[] | null>(null)
  let resolver: ((r: ResultadoSeleccion) => void) | null = null

  async function pedir(numero: number): Promise<ResultadoSeleccion> {
    let items: LineaSobreEncargo[] = []
    try {
      const { data } = await api.get<{ items?: LineaSobreEncargo[] }>(`/api/encargos/${numero}/sobres`)
      items = data.items ?? []
    } catch {
      // Sin la lista no se puede elegir: se imprime todo, como antes de existir la ventana.
      return undefined
    }
    if (!items.some((l) => l.sobres > 1)) return undefined
    return new Promise<ResultadoSeleccion>((resolve) => {
      resolver = resolve
      lineas.value = items
    })
  }

  function cerrar(r: ResultadoSeleccion) {
    lineas.value = null
    const res = resolver
    resolver = null
    res?.(r)
  }

  return {
    lineas,
    pedir,
    confirmar: (sel: SeleccionEtiquetaEncargo[]) => cerrar(sel),
    cancelar: () => cerrar(null),
  }
}
