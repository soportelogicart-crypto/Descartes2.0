import { ref } from 'vue'
import { listarEntidadCompleta } from '@/composables/useMantenimiento'

export type OpcionAlmacen = { value: number; label: string }

export function useAlmacenesOpciones(conTodos = true) {
  const almacenes = ref<OpcionAlmacen[]>(
    conTodos ? [{ value: 0, label: 'Todos los almacenes' }] : []
  )

  async function cargarAlmacenes() {
    try {
      const { items } = await listarEntidadCompleta('almacenes', { activo: true })
      const opts = items
        .map((row) => {
          const cod = Number(row.codigo ?? row.Codigo ?? 0)
          const desc = String(row.descripcion ?? row.Descripcion ?? '').trim()
          return { value: cod, label: desc ? `${cod} — ${desc}` : String(cod) }
        })
        .filter((o) => o.value > 0)
        .sort((a, b) => a.value - b.value)
      almacenes.value = conTodos
        ? [{ value: 0, label: 'Todos los almacenes' }, ...opts]
        : opts
    } catch {
      /* opcional */
    }
  }

  return { almacenes, cargarAlmacenes }
}
