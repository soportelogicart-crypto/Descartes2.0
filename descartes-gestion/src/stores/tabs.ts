import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type AppTab = {
  /** fullPath de vue-router (incluye query) */
  id: string
  title: string
  fullPath: string
}

const MAX_TABS = 8

/**
 * Listado y ficha de venta (/ventas, /ventas/nuevo, /ventas/{emp}/{tipo}/{n})
 * comparten pestaña, igual que albaranes de compra.
 * Pedidos de cliente: alta y ficha juntas (el listado sigue aparte).
 * Compras: listado y ficha de albarán / pedido / factura comparten pestaña.
 */
function tabIdFromPath(fullPath: string): string {
  const path = (fullPath.split('?')[0] || '/').replace(/\/+$/, '') || '/'
  if (/^\/ventas\/pedidos\/[^/]+\/\d+$/.test(path)) {
    return '/ventas/pedidos/ficha'
  }
  if (path === '/ventas/pedidos/nuevo') {
    return '/ventas/pedidos/ficha'
  }
  if (path === '/ventas' || path === '/ventas/nuevo') {
    return '/ventas'
  }
  // Ficha venta: /ventas/{empresa}/{tipo}/{albaran} — excluye /ventas/pedidos/...
  if (/^\/ventas\/(?!pedidos(?:\/|$))[^/]+\/[^/]+\/\d+$/.test(path)) {
    return '/ventas'
  }
  if (path === '/compras/albaranes' || path.startsWith('/compras/albaranes/')) {
    return '/compras/albaranes'
  }
  if (path === '/compras/pedidos' || path.startsWith('/compras/pedidos/')) {
    return '/compras/pedidos'
  }
  if (path === '/compras/facturas' || /^\/compras\/facturas\/\d+$/.test(path)) {
    return '/compras/facturas'
  }
  if (/^\/mantenimiento\/articulos$/.test(path)) {
    return '/mantenimiento/articulos'
  }
  return fullPath || '/'
}

function tituloDesdeRuta(fullPathOrPath: string, metaTitulo?: string): string {
  const pathOnly = (fullPathOrPath.split('?')[0] || '/').replace(/\/+$/, '') || '/'
  const partes = pathOnly.split('/').filter(Boolean)
  // Ficha compra: preferir nº de documento frente al meta genérico.
  if (partes[0] === 'compras' && partes[1] === 'albaranes') {
    if (partes[2] === 'nuevo') return 'Nuevo alb. compra'
    if (partes.length >= 4 && /^\d+$/.test(partes[3])) {
      return `Alb. compra ${partes[2]}-${partes[3]}`
    }
  }
  if (partes[0] === 'compras' && partes[1] === 'pedidos') {
    if (partes[2] === 'nuevo') return 'Nuevo ped. proveedor'
    if (partes.length >= 4 && /^\d+$/.test(partes[3])) {
      return `Ped. proveedor ${partes[2]}-${partes[3]}`
    }
  }
  if (partes[0] === 'compras' && partes[1] === 'facturas') {
    if (partes.length >= 3 && /^\d+$/.test(partes[2])) {
      return `Fact. proveedor ${partes[2]}`
    }
  }
  if (partes[0] === 'mantenimiento' && partes[1] === 'articulos') {
    const q = fullPathOrPath.includes('?')
      ? new URLSearchParams(fullPathOrPath.split('?')[1] ?? '')
      : null
    const codigo = q?.get('codigo')?.trim()
    if (codigo) return `Artículo ${codigo}`
    if (q?.get('nuevo') === '1') return 'Nuevo artículo'
    return metaTitulo?.trim() || 'Artículos'
  }
  if (metaTitulo && metaTitulo.trim()) return metaTitulo.trim()
  if (pathOnly === '/' || pathOnly === '') return 'Inicio'
  if (partes[0] === 'compras') {
    if (partes[1] === 'albaranes') return 'Albaranes de compra'
    if (partes[1] === 'pedidos') return 'Pedidos a proveedor'
    if (partes[1] === 'facturas') return 'Facturas de proveedor'
    return 'Compras'
  }
  if (partes[0] === 'ventas') {
    if (partes.length === 1) return 'Ventas'
    if (partes[1] === 'nuevo') return 'Nueva venta'
    if (partes[1] === 'pedidos') {
      if (partes[2] === 'nuevo') return 'Nuevo pedido'
      if (partes.length >= 4 && /^\d+$/.test(partes[3])) return `Pedido ${partes[3]}`
      return 'Pedidos'
    }
    if (partes[1] === 'arqueo' && partes[2] === 'desglose') return 'Desglose arqueo'
    if (partes[1] === 'arqueo') return 'Arqueo'
    if (partes.length >= 4 && /^\d+$/.test(partes[3])) return `Venta ${partes[2]}-${partes[3]}`
    return 'Ventas'
  }
  if (partes[0] === 'facturacion') {
    if (partes[1] === 'manual') return 'Facturas Manual'
    if (partes[1] === 'contabilidad') return 'Traspaso contable'
    if (partes[1] === 'generacion') return 'Generación facturas'
    if (partes[1] === 'impresion') return 'Impresión facturas'
    if (partes[1] === 'diario') return 'Diario facturación'
    if (partes[1] === 'albaranes-pendientes') return 'Alb. pendientes'
    if (partes[1] === 'retroceso') return 'Retroceso facturas'
    return 'Facturación'
  }
  if (partes[0] === 'mantenimiento') {
    return metaTitulo || partes[1] || 'Mantenimiento'
  }
  return metaTitulo || partes[partes.length - 1] || 'Pestaña'
}

export const useTabsStore = defineStore('tabs', () => {
  const tabs = ref<AppTab[]>([])
  const activeId = ref<string | null>(null)

  const activas = computed(() => tabs.value)
  const activa = computed(() => tabs.value.find((t) => t.id === activeId.value) ?? null)

  function openOrActivate(fullPath: string, metaTitulo?: string) {
    const pathOnly = fullPath.split('?')[0] || '/'
    const id = tabIdFromPath(fullPath)
    const existing = tabs.value.find((t) => t.id === id)
    if (existing) {
      existing.fullPath = fullPath || '/'
      existing.title = tituloDesdeRuta(fullPath, metaTitulo)
      activeId.value = existing.id
      return
    }

    if (tabs.value.length >= MAX_TABS) {
      // Cierra la pestaña más antigua que no sea la activa.
      const idx = tabs.value.findIndex((t) => t.id !== activeId.value)
      if (idx >= 0) tabs.value.splice(idx, 1)
      else tabs.value.shift()
    }

    tabs.value.push({
      id,
      fullPath: fullPath || '/',
      title: tituloDesdeRuta(fullPath, metaTitulo),
    })
    activeId.value = id
  }

  /** @returns fullPath a navegar tras cerrar, o null */
  function close(id: string): string | null {
    const i = tabs.value.findIndex((t) => t.id === id)
    if (i < 0) return null
    const wasActive = activeId.value === id
    tabs.value.splice(i, 1)
    if (!wasActive) return null
    if (tabs.value.length === 0) {
      activeId.value = null
      return '/'
    }
    const next = tabs.value[Math.min(i, tabs.value.length - 1)]
    activeId.value = next.id
    return next.fullPath
  }

  function closeOthers(id: string) {
    tabs.value = tabs.value.filter((t) => t.id === id)
    activeId.value = id
  }

  function clear() {
    tabs.value = []
    activeId.value = null
  }

  return {
    tabs,
    activeId,
    activas,
    activa,
    openOrActivate,
    close,
    closeOthers,
    clear,
    MAX_TABS,
  }
})
