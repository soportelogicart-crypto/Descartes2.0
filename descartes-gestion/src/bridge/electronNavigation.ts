import type { Router } from 'vue-router'
import { isElectronShell } from '@/bridge/electron'

const NAV_EVENT = 'descartes:navigate'

/**
 * Enlace menu Electron (Descartes > Conexion) con Vue Router.
 */
export function registerElectronNavigation(router: Router): void {
  if (!isElectronShell()) return
  reenfocarTrasDialogosNativos()

  window.addEventListener(NAV_EVENT, (event) => {
    const path = (event as CustomEvent<string>).detail
    if (typeof path === 'string' && path.startsWith('/')) {
      void router.push(path)
    }
  })
}

/**
 * Electron en Windows deja los inputs sin teclado tras alert/confirm/prompt
 * nativos hasta que la ventana pierde y recupera el foco.
 */
function reenfocarTrasDialogosNativos(): void {
  const reenfocar = window.descartes?.reenfocar
  if (!reenfocar) return
  for (const nombre of ['alert', 'confirm', 'prompt'] as const) {
    const original = window[nombre].bind(window) as (...args: unknown[]) => unknown
    ;(window as unknown as Record<string, unknown>)[nombre] = (...args: unknown[]) => {
      try {
        return original(...args)
      } finally {
        reenfocar()
      }
    }
  }
}
