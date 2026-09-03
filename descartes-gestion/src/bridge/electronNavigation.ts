import type { Router } from 'vue-router'
import { isElectronShell } from '@/bridge/electron'

const NAV_EVENT = 'descartes:navigate'

/**
 * Enlace menu Electron (Descartes > Conexion) con Vue Router.
 */
export function registerElectronNavigation(router: Router): void {
  if (!isElectronShell()) return

  window.addEventListener(NAV_EVENT, (event) => {
    const path = (event as CustomEvent<string>).detail
    if (typeof path === 'string' && path.startsWith('/')) {
      void router.push(path)
    }
  })
}
