import { onMounted, onUnmounted, ref, type MaybeRefOrGetter, toValue } from 'vue'
import { extractApiError } from '@/composables/useMantenimiento'

type FilaEliminable = {
  codigo?: string
  descripcion?: string
  _nuevo?: boolean
}

export function useEliminarFilaGrid(options: {
  puedeEliminar: MaybeRefOrGetter<boolean>
  filaSeleccionada: MaybeRefOrGetter<FilaEliminable | null>
  eliminarApi: (codigo: string) => Promise<void>
  recargar: () => Promise<void>
  quitarFilaNueva: () => void
  setMensaje: (msg: string | null) => void
  etiquetaEntidad: string
  mensajeExito?: string
}) {
  const confirmOpen = ref(false)
  const confirmMessage = ref('')

  function mensajeConfirmacion(fila: FilaEliminable): string {
    const codigo = String(fila.codigo ?? '').trim()
    const descripcion = String(fila.descripcion ?? '').trim()
    const detalle = descripcion ? `${codigo} - ${descripcion}` : codigo
    return `Va a dar de baja ${options.etiquetaEntidad} ${detalle}.`
  }

  function solicitarEliminar() {
    if (!toValue(options.puedeEliminar)) return

    const fila = toValue(options.filaSeleccionada)
    if (!fila) return

    // Fila "*" de alta: no es un borrado; no abrir dialogo ni hacer ruido.
    if (fila._nuevo) return

    const codigo = String(fila.codigo ?? '').trim()
    if (!codigo) return

    confirmMessage.value = mensajeConfirmacion(fila)
    confirmOpen.value = true
  }

  async function confirmarEliminar() {
    const fila = toValue(options.filaSeleccionada)
    const codigo = String(fila?.codigo ?? '').trim()
    confirmOpen.value = false
    if (!codigo || fila?._nuevo) return

    try {
      await options.eliminarApi(codigo)
      await options.recargar()
      options.setMensaje(options.mensajeExito ?? 'Registro eliminado')
    } catch (e: unknown) {
      options.setMensaje(extractApiError(e, 'No se pudo eliminar el registro'))
    }
  }

  function cancelarEliminar() {
    confirmOpen.value = false
  }

  function onKeydown(e: KeyboardEvent) {
    if (e.key !== 'Delete') return
    const tag = (e.target as HTMLElement).tagName
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return
    e.preventDefault()
    solicitarEliminar()
  }

  onMounted(() => window.addEventListener('keydown', onKeydown))
  onUnmounted(() => window.removeEventListener('keydown', onKeydown))

  return {
    confirmOpen,
    confirmMessage,
    solicitarEliminar,
    confirmarEliminar,
    cancelarEliminar,
  }
}
