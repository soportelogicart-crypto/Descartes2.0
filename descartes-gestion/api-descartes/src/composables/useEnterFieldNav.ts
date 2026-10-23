function elementoVisible(el: HTMLElement): boolean {
  if (!el.getClientRects().length) return false
  const st = getComputedStyle(el)
  return st.visibility !== 'hidden' && st.display !== 'none'
}

/** Hay un modal realmente visible (v-show deja nodos ocultos en el DOM). */
export function hayModalVisible(): boolean {
  return Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]')).some(
    (n) => n instanceof HTMLElement && elementoVisible(n),
  )
}

/** Campos focusables visibles dentro de un contenedor (Intro → siguiente, como legacy). */
export function focusablesEnContenedor(root: HTMLElement): HTMLElement[] {
  return Array.from(
    root.querySelectorAll<HTMLElement>(
      'input:not([type=checkbox]):not([disabled]), select:not([disabled]), textarea:not([disabled])',
    ),
  ).filter((el) => elementoVisible(el))
}

function campoDesdeTarget(target: HTMLElement, root: HTMLElement): HTMLElement | null {
  if (target.matches('input, select, textarea') && root.contains(target)) return target
  const inner = target.closest('input, select, textarea')
  if (inner instanceof HTMLElement && root.contains(inner)) return inner
  return null
}

export type EnterFieldNavOptions = {
  /** Si hay un modal abierto encima, no interceptar Intro. */
  skipIfModalOpen?: boolean
  /** Al pulsar Intro en el último campo del contenedor. */
  onUltimo?: () => void
}

/**
 * Intro / NumpadEnter: foco al siguiente campo editable; opcional acción en el último.
 */
export function onEnterSiguienteCampo(
  e: KeyboardEvent,
  root: HTMLElement | null | undefined,
  opts?: EnterFieldNavOptions,
) {
  if (e.key !== 'Enter' && e.key !== 'NumpadEnter') return
  if (e.isComposing) return
  if (opts?.skipIfModalOpen !== false && hayModalVisible()) {
    return
  }

  const raw = e.target
  if (!(raw instanceof HTMLElement) || !root) return
  const target = campoDesdeTarget(raw, root)
  if (!target) return

  const list = focusablesEnContenedor(root)
  const idx = list.indexOf(target)
  if (idx === -1) return

  e.preventDefault()

  if (idx < list.length - 1) {
    const next = list[idx + 1]
    next.focus()
    if (next instanceof HTMLInputElement && next.type !== 'date') {
      next.select()
    }
    return
  }

  opts?.onUltimo?.()
}
