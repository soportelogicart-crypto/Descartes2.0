import { ref } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usePermisos } from '@/composables/usePermisos'
import LoginView from '@/views/LoginView.vue'
import InstalacionView from '@/views/InstalacionView.vue'
import EmpresaClienteView from '@/views/mantenimiento/EmpresaClienteView.vue'
import EntidadView from '@/views/mantenimiento/EntidadView.vue'
import AppLayout from '@/components/layout/AppLayout.vue'
import ArticuloSeccionPlaceholder from '@/views/mantenimiento/ArticuloSeccionPlaceholder.vue'
import MacrofamiliasView from '@/views/mantenimiento/MacrofamiliasView.vue'
import FamiliasView from '@/views/mantenimiento/FamiliasView.vue'
import SubfamiliasView from '@/views/mantenimiento/SubfamiliasView.vue'
import AgrupacionesView from '@/views/mantenimiento/AgrupacionesView.vue'
import ActividadesView from '@/views/mantenimiento/ActividadesView.vue'
import InteresesComercialesView from '@/views/mantenimiento/InteresesComercialesView.vue'
import OfertaClientesView from '@/views/mantenimiento/OfertaClientesView.vue'
import CampanasView from '@/views/mantenimiento/CampanasView.vue'
import AlbaranesPeriodicosView from '@/views/mantenimiento/AlbaranesPeriodicosView.vue'
import ParametrosPuestoView from '@/views/mantenimiento/ParametrosPuestoView.vue'
import PuestosView from '@/views/mantenimiento/PuestosView.vue'
import ImpuestosView from '@/views/mantenimiento/ImpuestosView.vue'
import FormasPagoView from '@/views/mantenimiento/FormasPagoView.vue'
import ProveedoresView from '@/views/mantenimiento/ProveedoresView.vue'
import OfertaProveedoresView from '@/views/mantenimiento/OfertaProveedoresView.vue'
import HomeView from '@/views/HomeView.vue'
import ModuloPlaceholderView from '@/views/ModuloPlaceholderView.vue'
import ComprasAlbaranesListView from '@/views/compras/ComprasAlbaranesListView.vue'
import CompraAlbaranDetalleView from '@/views/compras/CompraAlbaranDetalleView.vue'
import ComprasPedidosListView from '@/views/compras/ComprasPedidosListView.vue'
import CompraPedidoDetalleView from '@/views/compras/CompraPedidoDetalleView.vue'
import ComprasFacturasListView from '@/views/compras/ComprasFacturasListView.vue'
import CompraFacturaDetalleView from '@/views/compras/CompraFacturaDetalleView.vue'
import EtiquetasColaView from '@/views/etiquetas/EtiquetasColaView.vue'
import VentasListView from '@/views/ventas/VentasListView.vue'
import VentaDetalleView from '@/views/ventas/VentaDetalleView.vue'
import ArqueoView from '@/views/ventas/ArqueoView.vue'
import ArqueoDesgloseView from '@/views/ventas/ArqueoDesgloseView.vue'
import AnulacionesView from '@/views/ventas/AnulacionesView.vue'
import CobrosPagosView from '@/views/ventas/CobrosPagosView.vue'
import ValesView from '@/views/ventas/ValesView.vue'
import PedidosClientesView from '@/views/ventas/PedidosClientesView.vue'
import PedidoDetalleView from '@/views/ventas/PedidoDetalleView.vue'
import AbcVentasView from '@/views/ventas/AbcVentasView.vue'
import GeneracionFacturasManualView from '@/views/facturacion/GeneracionFacturasManualView.vue'
import GeneracionFacturasView from '@/views/facturacion/GeneracionFacturasView.vue'
import ImpresionFacturasView from '@/views/facturacion/ImpresionFacturasView.vue'
import DiarioFacturacionView from '@/views/facturacion/DiarioFacturacionView.vue'
import AlbaranesPendientesView from '@/views/facturacion/AlbaranesPendientesView.vue'
import RetrocesoFacturaView from '@/views/facturacion/RetrocesoFacturaView.vue'
import ConfiguracionHubView from '@/views/configuracion/ConfiguracionHubView.vue'
import DocumentosPlantillasView from '@/views/configuracion/DocumentosPlantillasView.vue'
import TpvVentaView from '@/views/tpv/TpvVentaView.vue'
import { getInstalacionEstado } from '@/api/instalacion'

const articulosSeccionesPendientes = [
  { path: 'mantenimiento/secciones', name: 'secciones', titulo: 'Secciones' },
  { path: 'mantenimiento/subsecciones', name: 'subsecciones', titulo: 'Subsecciones' },
] as const

const modulosPlaceholder = [
  { path: 'inventario', name: 'inventario', titulo: 'Inventario' },
  { path: 'listados', name: 'listados', titulo: 'Listados' },
] as const

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/instalacion', name: 'instalacion', component: InstalacionView, meta: { public: true } },
    {
      path: '/',
      component: AppLayout,
      children: [
        { path: '', name: 'home', component: HomeView },
        {
          path: 'configuracion',
          name: 'configuracion',
          component: ConfiguracionHubView,
          meta: { titulo: 'Configuración' },
        },
        {
          path: 'configuracion/base-datos',
          name: 'configuracion-base-datos',
          component: InstalacionView,
          meta: { titulo: 'Base de datos' },
        },
        {
          path: 'configuracion/documentos',
          redirect: '/configuracion/albaranes',
        },
        {
          path: 'configuracion/albaranes',
          name: 'configuracion-albaranes',
          component: DocumentosPlantillasView,
          meta: { titulo: 'Albaranes y facturas', plantillasScope: 'albaranes' },
        },
        {
          path: 'configuracion/tickets',
          name: 'configuracion-tickets',
          component: DocumentosPlantillasView,
          meta: { titulo: 'Tickets', plantillasScope: 'tickets' },
        },
        {
          path: 'configuracion/etiquetas',
          name: 'configuracion-etiquetas',
          component: DocumentosPlantillasView,
          meta: { titulo: 'Etiquetas', plantillasScope: 'etiquetas' },
        },
        ...modulosPlaceholder.map((m) => ({
          path: m.path,
          name: m.name,
          component: ModuloPlaceholderView,
          props: { titulo: m.titulo },
          meta: { titulo: m.titulo },
        })),
        {
          path: 'tpv',
          name: 'tpv',
          component: TpvVentaView,
          meta: { titulo: 'TPV', modulo: 'tpv', accion: 'ver', contentFlush: true },
        },
        {
          path: 'compras',
          redirect: '/compras/albaranes',
        },
        {
          path: 'compras/albaranes',
          name: 'compras-albaranes',
          component: ComprasAlbaranesListView,
          meta: { titulo: 'Albaranes de compra', modulo: 'compras', accion: 'ver' },
        },
        {
          path: 'compras/albaranes/nuevo',
          name: 'compras-albaran-nuevo',
          component: CompraAlbaranDetalleView,
          meta: { titulo: 'Nuevo albarán de compra', modulo: 'compras', accion: 'crear' },
        },
        {
          path: 'compras/albaranes/:empresa/:albaran',
          name: 'compras-albaran-detalle',
          component: CompraAlbaranDetalleView,
          meta: { titulo: 'Albarán de compra', modulo: 'compras', accion: 'ver' },
        },
        // Pedidos a proveedor (US3); facturas: shell hasta US5
        {
          path: 'compras/pedidos',
          name: 'compras-pedidos',
          component: ComprasPedidosListView,
          meta: { titulo: 'Pedidos a proveedor', modulo: 'compras', accion: 'ver' },
        },
        {
          path: 'compras/pedidos/nuevo',
          name: 'compras-pedido-nuevo',
          component: CompraPedidoDetalleView,
          meta: { titulo: 'Nuevo pedido a proveedor', modulo: 'compras', accion: 'crear' },
        },
        {
          path: 'compras/pedidos/:empresa/:pedido',
          name: 'compras-pedido-detalle',
          component: CompraPedidoDetalleView,
          meta: { titulo: 'Pedido a proveedor', modulo: 'compras', accion: 'ver' },
        },
        {
          path: 'compras/facturas',
          name: 'compras-facturas',
          component: ComprasFacturasListView,
          meta: { titulo: 'Facturas de proveedor', modulo: 'compras', accion: 'ver' },
        },
        {
          path: 'compras/facturas/:factura',
          name: 'compras-factura-detalle',
          component: CompraFacturaDetalleView,
          meta: { titulo: 'Factura de proveedor', modulo: 'compras', accion: 'ver' },
        },
        {
          path: 'etiquetas',
          name: 'etiquetas-cola',
          component: EtiquetasColaView,
          meta: { titulo: 'Etiquetas', modulo: 'etiquetas', accion: 'ver' },
        },
        {
          path: 'ventas',
          name: 'ventas',
          component: VentasListView,
          meta: { titulo: 'Ventas', modulo: 'ventas', accion: 'ver' },
        },
        {
          path: 'ventas/nuevo',
          name: 'ventas-nuevo',
          component: VentaDetalleView,
          meta: { titulo: 'Nueva venta', modulo: 'ventas', accion: 'crear' },
        },
        {
          path: 'ventas/arqueo',
          name: 'ventas-arqueo',
          component: ArqueoView,
          meta: { titulo: 'Arqueo', modulo: 'ventas-arqueo', accion: 'ver' },
        },
        {
          path: 'ventas/arqueo/desglose',
          name: 'ventas-arqueo-desglose',
          component: ArqueoDesgloseView,
          meta: { titulo: 'Desglose de arqueo', modulo: 'ventas-arqueo-desglose', accion: 'ver' },
        },
        {
          path: 'ventas/anulaciones',
          name: 'ventas-anulaciones',
          component: AnulacionesView,
          meta: { titulo: 'Anulaciones', modulo: 'ventas-anulaciones', accion: 'ver' },
        },
        {
          path: 'ventas/cobros-pagos',
          name: 'ventas-cobros-pagos',
          component: CobrosPagosView,
          meta: { titulo: 'Cobros y Pagos', modulo: 'ventas-cobros-pagos', accion: 'ver' },
        },
        {
          path: 'ventas/vales',
          name: 'ventas-vales',
          component: ValesView,
          meta: { titulo: 'Vales', modulo: 'ventas-vales', accion: 'ver' },
        },
        {
          path: 'ventas/pedidos',
          name: 'ventas-pedidos',
          component: PedidosClientesView,
          meta: { titulo: 'Pedidos', modulo: 'ventas-pedidos', accion: 'ver' },
        },
        {
          path: 'ventas/pedidos/nuevo',
          name: 'ventas-pedidos-nuevo',
          component: PedidoDetalleView,
          meta: { titulo: 'Nuevo pedido', modulo: 'ventas-pedidos', accion: 'ver' },
        },
        {
          path: 'ventas/pedidos/:empresa/:pedido',
          name: 'ventas-pedidos-detalle',
          component: PedidoDetalleView,
          meta: { titulo: 'Pedido', modulo: 'ventas-pedidos', accion: 'ver' },
        },
        {
          path: 'ventas/abc',
          name: 'ventas-abc',
          component: AbcVentasView,
          meta: { titulo: 'Listado ABC Ventas', modulo: 'ventas-abc', accion: 'ver' },
        },
        {
          path: 'facturacion',
          redirect: '/facturacion/generacion',
        },
        {
          path: 'facturacion/generacion',
          name: 'facturacion-generacion',
          component: GeneracionFacturasView,
          meta: {
            titulo: 'Generación de facturas',
            modulo: 'facturacion-generacion',
            accion: 'ver',
          },
        },
        {
          path: 'facturacion/manual',
          name: 'facturacion-manual',
          component: GeneracionFacturasManualView,
          meta: {
            titulo: 'Generador de facturas Manual',
            modulo: 'facturacion-manual',
            accion: 'ver',
          },
        },
        {
          path: 'facturacion/impresion',
          name: 'facturacion-impresion',
          component: ImpresionFacturasView,
          meta: {
            titulo: 'Impresión de facturas',
            modulo: 'facturacion-impresion',
            accion: 'ver',
          },
        },
        {
          path: 'facturacion/diario',
          name: 'facturacion-diario',
          component: DiarioFacturacionView,
          meta: {
            titulo: 'Diario de facturación',
            modulo: 'facturacion-diario',
            accion: 'ver',
          },
        },
        {
          path: 'facturacion/albaranes-pendientes',
          name: 'facturacion-albaranes-pendientes',
          component: AlbaranesPendientesView,
          meta: {
            titulo: 'Albaranes pendientes de facturar',
            modulo: 'facturacion-albaranes-pendientes',
            accion: 'ver',
          },
        },
        {
          path: 'facturacion/retroceso',
          name: 'facturacion-retroceso',
          component: RetrocesoFacturaView,
          meta: {
            titulo: 'Retroceso de facturas',
            modulo: 'facturacion-retroceso',
            accion: 'ver',
          },
        },
        {
          path: 'ventas/:empresa/:tipo/:albaran',
          name: 'ventas-detalle',
          component: VentaDetalleView,
          meta: { titulo: 'Detalle venta', modulo: 'ventas', accion: 'ver' },
        },
        { path: 'mantenimiento/empresas', name: 'empresas', component: EmpresaClienteView },
        { path: 'mantenimiento/macrofamilias', name: 'macrofamilias', component: MacrofamiliasView },
        { path: 'mantenimiento/familias', name: 'familias', component: FamiliasView },
        { path: 'mantenimiento/subfamilias', name: 'subfamilias', component: SubfamiliasView },
        { path: 'mantenimiento/agrupaciones', name: 'agrupaciones', component: AgrupacionesView },
        { path: 'mantenimiento/actividades', name: 'actividades', component: ActividadesView },
        {
          path: 'mantenimiento/intereses-comerciales',
          name: 'intereses-comerciales',
          component: InteresesComercialesView,
        },
        {
          path: 'mantenimiento/oferta-clientes',
          name: 'oferta-clientes',
          component: OfertaClientesView,
        },
        {
          path: 'mantenimiento/campanas',
          name: 'campanas',
          component: CampanasView,
        },
        {
          path: 'mantenimiento/albaranes-periodicos',
          name: 'albaranes-periodicos',
          component: AlbaranesPeriodicosView,
          meta: {
            titulo: 'Albaranes periódicos',
            modulo: 'albaranes-periodicos',
            accion: 'ver',
          },
        },
        {
          path: 'mantenimiento/puestos/parametros',
          name: 'puestos-parametros',
          component: ParametrosPuestoView,
        },
        { path: 'mantenimiento/puestos-trabajo', name: 'puestos-trabajo', component: PuestosView },
        { path: 'mantenimiento/impuestos', name: 'impuestos', component: ImpuestosView },
        { path: 'mantenimiento/formas-pago', name: 'formas-pago', component: FormasPagoView },
        { path: 'mantenimiento/proveedores', name: 'proveedores', component: ProveedoresView },
        {
          path: 'mantenimiento/oferta-proveedores',
          name: 'oferta-proveedores',
          component: OfertaProveedoresView,
          meta: { titulo: 'Ofertas proveedores' },
        },
        ...articulosSeccionesPendientes.map((seccion) => ({
          path: seccion.path,
          name: seccion.name,
          component: ArticuloSeccionPlaceholder,
          meta: { titulo: seccion.titulo },
        })),
        { path: 'mantenimiento/:entidad', name: 'mantenimiento', component: EntidadView },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  if (to.name !== 'instalacion') {
    try {
      const estado = await getInstalacionEstado()
      if (estado.requiereAccion) {
        return { name: 'instalacion', query: to.fullPath !== '/' ? { redirect: to.fullPath } : undefined }
      }
    } catch {
      if (to.name !== 'login') {
        return { name: 'instalacion' }
      }
    }
  }

  if (to.meta.public) return true

  const auth = useAuthStore()
  if (!auth.cargado) {
    try {
      await auth.fetchMe()
    } catch {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  const modulo = to.meta.modulo as string | undefined
  const accion = (to.meta.accion as string | undefined) ?? 'ver'
  if (modulo) {
    const { puede } = usePermisos()
    if (!puede(modulo, accion)) {
      return { name: 'home' }
    }
  }

  return true
})

/** Ruta anterior a la navegación actual (p. ej. reactivación KeepAlive). */
export const routeNavigationFrom = ref<string | undefined>(undefined)

/** Tras guardar cabecera en alta: foco en primera línea al abrir la ficha. */
export const albaranCompraFocusLineas = ref<{ empresa: string; albaran: number } | null>(null)

router.afterEach((_to, from) => {
  routeNavigationFrom.value = from.fullPath
})

export default router
