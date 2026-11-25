<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { articulosMenuItems, esRutaArticulos } from '@/config/articulos-menu'
import { clientesMenuItems, esRutaClientes } from '@/config/clientes-menu'
import { puestosMenuItems, esRutaPuestos } from '@/config/puestos-menu'
import { proveedoresMenuItems, esRutaProveedores } from '@/config/proveedores-menu'
import { ventasMenuItems } from '@/config/ventas-nav'
import { facturacionMenuItems } from '@/config/facturacion-nav'
import { comprasMenuItems } from '@/config/compras-nav'
import { inventarioMenuItems } from '@/config/inventario-nav'
import { etiquetasMenuItems } from '@/config/etiquetas-nav'
import { moduloDeEntradaMenu } from '@/config/mantenimiento-nav-permisos'
import { menuPrincipalSecciones } from '@/config/menu-principal'
import { puedeAccederHubListados } from '@/config/listados-nav'
import { abcComprasListadoPorDimension } from '@/config/abc-compras-dimensiones'
import { abcVentasListadoPorDimension } from '@/config/abc-ventas-dimensiones'
import { stockListadoPorAgrupar } from '@/config/stock-listado-config'
import { entidades } from '@/config/entidades'
import { usePermisos } from '@/composables/usePermisos'
import PuestoEquipoModal from '@/components/puestos/PuestoEquipoModal.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EsperaModal from '@/components/common/EsperaModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useTabsStore } from '@/stores/tabs'
import AppTabs from '@/components/layout/AppTabs.vue'
import {
  estadoAutomaticoFidelizacion,
  generarAutomaticamenteFidelizacion,
} from '@/api/ventas'
import { extractApiError } from '@/composables/extractApiError'
import { getInstalacionEstado } from '@/api/instalacion'
import { useCarpetaCompartidaStore } from '@/stores/carpetaCompartida'
import CarpetaCompartidaModal from '@/components/carpeta/CarpetaCompartidaModal.vue'

const auth = useAuthStore()
const { t, locale } = useI18n()
const carpetaStore = useCarpetaCompartidaStore()
const modalCarpeta = ref(false)
const nombreBaseDatos = ref('')
const etiquetaRol = computed(() => {
  const rol = auth.usuario?.rolNombre?.trim() || auth.usuario?.rolCodigo?.trim() || ''
  return rol
})

onMounted(async () => {
  void carpetaStore.cargar()
  try {
    const estado = await getInstalacionEstado()
    nombreBaseDatos.value = (estado.diagnostico?.baseDatos || estado.database || '').trim()
  } catch {
    nombreBaseDatos.value = ''
  }
})
const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()

const etiquetaTienda = computed(() => {
  const nombre = puestoContexto.tiendaNombre?.trim() ?? ''
  if (nombre) return nombre
  return puestoContexto.empresaCodigo?.trim() ?? ''
})

onMounted(() => {
  void puestoContexto.cargarTiendasPuesto()
})

watch(
  () => [puestoContexto.puestoCodigo, auth.usuario?.codigo],
  () => {
    void puestoContexto.cargarTiendasPuesto()
  }
)

function onCambiarTienda(evento: Event) {
  const codigo = (evento.target as HTMLSelectElement).value
  const tienda = puestoContexto.tiendas.find((item) => item.tienda === codigo)
  if (tienda) puestoContexto.elegirTienda(tienda.tienda, tienda.tiendaNombre)
}
const tabsStore = useTabsStore()
const route = useRoute()
const router = useRouter()

const articulosAbierto = ref(false)
const clientesAbierto = ref(false)
const puestosAbierto = ref(false)
const proveedoresAbierto = ref(false)
const modalEquipoAbierto = ref(false)
const generandoFidelizacion = ref(false)
const avisoFidelizacion = ref('')
const comprobacionesFidelizacion = new Set<string>()

// Toda la aplicación usa una franja de iconos. El menú completo se abre
// flotando para no reducir el ancho útil de la pantalla actual.
const menuDesplegado = ref(false)
const mostrarTexto = computed(() => menuDesplegado.value)

const seccionesAbiertas = reactive<Record<string, boolean>>(
  Object.fromEntries(menuPrincipalSecciones.map((s) => [s.id, false]))
)

const ventasItems = computed(() =>
  ventasMenuItems.map((item) => ({
    ...item,
    habilitado: puede(item.modulo, 'ver'),
  }))
)

const ventasSeccionVisible = computed(() => ventasItems.value.some((i) => i.habilitado))

const comprasItems = computed(() =>
  comprasMenuItems.map((item) => ({
    ...item,
    habilitado: puede(item.modulo, 'ver'),
  }))
)

const comprasSeccionVisible = computed(() => comprasItems.value.some((i) => i.habilitado))

const etiquetasItems = computed(() =>
  etiquetasMenuItems.map((item) => ({
    ...item,
    habilitado: puede(item.modulo, 'ver'),
  }))
)

const etiquetasSeccionVisible = computed(() => etiquetasItems.value.some((i) => i.habilitado))

const facturacionItems = computed(() =>
  facturacionMenuItems.map((item) => ({
    ...item,
    habilitado: puede(item.modulo, 'ver') || puede('facturacion', 'ver'),
  }))
)

const facturacionSeccionVisible = computed(() =>
  facturacionItems.value.some((i) => i.habilitado) || puede('facturacion', 'ver')
)

const inventarioItems = computed(() =>
  inventarioMenuItems.map((item) => ({
    ...item,
    habilitado: puede('inventario', 'ver') && puede(item.modulo, 'ver'),
  }))
)

const inventarioSeccionVisible = computed(() => inventarioItems.value.some((i) => i.habilitado))

const secciones = computed(() =>
  menuPrincipalSecciones.map((seccion) => ({
    ...seccion,
    // Ocultar seccion si no hay submenu permitido (compras / etiquetas / ventas / facturacion).
    habilitado:
      seccion.id === 'compras'
        ? comprasSeccionVisible.value
        : seccion.id === 'etiquetas'
          ? etiquetasSeccionVisible.value
          : seccion.id === 'ventas'
            ? ventasSeccionVisible.value
            : seccion.id === 'facturacion'
              ? facturacionSeccionVisible.value
              : seccion.id === 'tpv'
                ? puede('tpv', 'ver')
                : seccion.id === 'listados'
                  ? puedeAccederHubListados(puede)
                  : seccion.id === 'inventario'
                    ? inventarioSeccionVisible.value
                    : true,
    activa:
      seccion.id === 'mantenimiento'
        ? route.path.startsWith('/mantenimiento')
        : seccion.id === 'inventario'
          ? route.path.startsWith('/inventario') || route.path.startsWith('/listados/stock')
          : route.path === seccion.ruta || route.path.startsWith(`${seccion.ruta}/`),
  }))
)

const menuMantenimiento = computed(() =>
  Object.entries(entidades)
    .filter(
      ([slug]) =>
        slug !== 'articulos' &&
        slug !== 'puestos-trabajo' &&
        slug !== 'clientes' &&
        slug !== 'proveedores' &&
        slug !== 'empresas'
    )
    .map(([slug, cfg]) => ({
      slug,
      titulo: cfg.titulo,
      ruta: cfg.singleton ? '/mantenimiento/empresas' : `/mantenimiento/${slug}`,
      habilitado: puede(cfg.modulo, 'ver'),
    }))
)

const articulosItems = computed(() =>
  articulosMenuItems.map((item) => ({
    ...item,
    habilitado: puede(moduloDeEntradaMenu(item.slug), 'ver'),
  }))
)
const clientesItems = computed(() =>
  clientesMenuItems.map((item) => ({
    ...item,
    habilitado: puede(moduloDeEntradaMenu(item.slug), 'ver'),
  }))
)
const proveedoresItems = computed(() =>
  proveedoresMenuItems.map((item) => ({
    ...item,
    habilitado: puede(moduloDeEntradaMenu(item.slug), 'ver'),
  }))
)
const puestosItems = computed(() =>
  puestosMenuItems.map((item) => ({
    ...item,
    habilitado: puede(moduloDeEntradaMenu(item.slug), 'ver'),
  }))
)

/**
 * Clave de KeepAlive. En mantenimiento la rejilla y la ficha son la misma vista con
 * distinta query: incluirla crearia una instancia por articulo y la pantalla parpadea
 * (vuelve a la rejilla) al pasar de ficha con las flechas.
 */
function claveVistaCacheada(r: { path: string; fullPath: string }): string {
  const base = r.path.startsWith('/mantenimiento/') ? r.path : r.fullPath
  const empresa = puestoContexto.empresaCodigo?.trim() ?? ''
  return empresa ? `${base}@${empresa}` : base
}

const articulosActivo = computed(() => esRutaArticulos(route.path))
const clientesActivo = computed(() => esRutaClientes(route.path))
const puestosActivo = computed(() => esRutaPuestos(route.path))
const proveedoresActivo = computed(() => esRutaProveedores(route.path))
const mantenimientoActivo = computed(() => route.path.startsWith('/mantenimiento'))

/** Título de un informe del catálogo, si la ruta es listados-… */
function tituloInformeRuta(): string | null {
  const name = String(route.name ?? '')
  if (!name.startsWith('listados-')) return null
  const id = name.slice('listados-'.length)
  const k = `informes.items.${id}.titulo`
  const v = t(k)
  return v === k ? null : v
}

/** Cabecera legacy: nombre de la pantalla actual, como la barra del TPV. */
const tituloPantalla = computed(() => {
  if (route.name === 'listados-stock' || route.path.startsWith('/listados/stock')) {
    const d = stockListadoPorAgrupar(String(route.params.agruparPor ?? ''))
    if (d) return d.titulo
    const p = route.path.replace(/\/+$/, '') || '/'
    if (p === '/listados/stock') return t('informes.items.stock.titulo')
  }
  if (route.name === 'listados-abc-ventas' || route.path.startsWith('/listados/abc-ventas')) {
    const d = abcVentasListadoPorDimension(String(route.params.dimension ?? ''))
    if (d) return d.titulo
    const p = route.path.replace(/\/+$/, '') || '/'
    if (p === '/listados/abc-ventas') return t('informes.items.abc-ventas.titulo')
  }
  if (route.name === 'novedades') return t('novedades.titulo')
  const informe = tituloInformeRuta()
  if (informe) return informe
  if (route.name === 'listados-abc-compras' || route.path.startsWith('/listados/abc-compras')) {
    const d = abcComprasListadoPorDimension(String(route.params.dimension ?? ''))
    if (d) return d.titulo
    const p = route.path.replace(/\/+$/, '') || '/'
    if (p === '/listados/abc-compras') return t('informes.items.abc-compras.titulo')
  }
  return typeof route.meta.titulo === 'string' && route.meta.titulo.trim() !== ''
    ? route.meta.titulo
    : t('app.nombre')
})

function tituloParaPestana(): string | undefined {
  if (route.name === 'novedades') return t('novedades.titulo')
  return tituloInformeRuta() ?? (typeof route.meta.titulo === 'string' ? route.meta.titulo : undefined)
}

function tx(clave: string, fallback: string): string {
  const k = `menu.${clave}`
  const v = t(k)
  return v === k ? fallback : v
}

const ayudasActivas = computed(() => auth.usuario?.ayudasMenu !== false)
const ayudaVisible = ref(false)
const ayudaTexto = ref('')
const ayudaX = ref(0)
const ayudaY = ref(0)

function onMenuOver(ev: MouseEvent) {
  if (!ayudasActivas.value) {
    ayudaVisible.value = false
    return
  }
  const el = (ev.target as HTMLElement | null)?.closest('[data-ayuda]') as HTMLElement | null
  if (!el) return
  const clave = el.dataset.ayuda ?? ''
  const k = `ayuda.${clave}`
  const texto = t(k)
  if (!clave || texto === k) {
    ayudaVisible.value = false
    return
  }
  const rect = el.getBoundingClientRect()
  const ancho = 288
  let x = rect.right + 8
  let y = rect.top
  if (x + ancho > window.innerWidth) x = Math.max(8, rect.left - ancho - 8)
  if (y + 96 > window.innerHeight) y = Math.max(8, window.innerHeight - 104)
  ayudaTexto.value = texto
  ayudaX.value = x
  ayudaY.value = y
  ayudaVisible.value = true
}

function ocultarAyuda() {
  ayudaVisible.value = false
}

watch(
  () => route.fullPath,
  (fullPath) => {
    // Pestañas: cada pantalla consultada queda abierta (máx. 8).
    // El menu lateral no se reabre automaticamente al navegar.
    if (auth.cargado) {
      tabsStore.openOrActivate(fullPath, tituloParaPestana())
    }
  },
  { immediate: true }
)

watch(locale, () => {
  if (!auth.cargado) return
  tabsStore.openOrActivate(route.fullPath, tituloParaPestana())
})

watch(
  () => auth.cargado,
  async (cargado) => {
    if (!cargado) return
    // En Electron la configuración vive en el disco del PC, no en el navegador:
    // sin leerla antes, un localStorage vacío vuelve a preguntar el puesto.
    if (puestoContexto.enElectron || puestoContexto.equipoId) {
      await puestoContexto.hydrateFromApi()
    }
    if (!puestoContexto.configurado) {
      modalEquipoAbierto.value = true
      return
    }
    await comprobarFidelizacionAutomatica()
  },
  { immediate: true }
)

async function comprobarFidelizacionAutomatica() {
  const empresa = puestoContexto.empresaCodigo?.trim() ?? ''
  const puesto = puestoContexto.puestoCodigo?.trim() ?? ''
  if (!auth.cargado || !empresa || !puesto || comprobacionesFidelizacion.has(empresa)) return
  comprobacionesFidelizacion.add(empresa)
  try {
    const estado = await estadoAutomaticoFidelizacion(empresa)
    if (!estado.pendiente) return
    generandoFidelizacion.value = true
    const resultado = await generarAutomaticamenteFidelizacion(empresa, puesto)
    if (resultado.generadoAhora) {
      const loc = locale.value === 'en' ? 'en-GB' : locale.value === 'ca' ? 'ca-ES' : 'es-ES'
      avisoFidelizacion.value = t('fidelizacion.generado', {
        puesto,
        vales: resultado.vales,
        importe: resultado.importeTotal.toLocaleString(loc, {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        }),
      })
    }
  } catch (e: unknown) {
    const status = (e as { response?: { status?: number } }).response?.status
    if (status !== 403) {
      avisoFidelizacion.value = extractApiError(e, t('fidelizacion.error'))
    }
  } finally {
    generandoFidelizacion.value = false
  }
}

function toggleSeccion(id: string) {
  const seccion = secciones.value.find((s) => s.id === id)
  if (id === 'novedades' && seccion?.habilitado) {
    router.push('/novedades')
    cerrarMenu()
    return
  }
  if (id === 'tpv' && seccion?.habilitado) {
    router.push('/tpv')
    cerrarMenu()
    return
  }

  // El submenu necesita el ancho completo para ser legible.
  menuDesplegado.value = true

  const estabaAbierta = seccionesAbiertas[id]
  for (const key of Object.keys(seccionesAbiertas)) {
    seccionesAbiertas[key] = false
  }
  // Si ya estaba abierta, queda cerrada (todo recogido). Si no, abre solo esta.
  seccionesAbiertas[id] = !estabaAbierta
  if (!seccionesAbiertas[id]) {
    articulosAbierto.value = false
    clientesAbierto.value = false
    puestosAbierto.value = false
    proveedoresAbierto.value = false
  }
}

function cerrarMenu() {
  for (const id of Object.keys(seccionesAbiertas)) {
    seccionesAbiertas[id] = false
  }
  articulosAbierto.value = false
  clientesAbierto.value = false
  puestosAbierto.value = false
  proveedoresAbierto.value = false
  menuDesplegado.value = false
}

function toggleMenu() {
  if (menuDesplegado.value) {
    cerrarMenu()
  } else {
    menuDesplegado.value = true
  }
}

function toggleArticulos() {
  articulosAbierto.value = !articulosAbierto.value
}

function toggleClientes() {
  clientesAbierto.value = !clientesAbierto.value
}

function togglePuestos() {
  puestosAbierto.value = !puestosAbierto.value
}

function toggleProveedores() {
  proveedoresAbierto.value = !proveedoresAbierto.value
}

function onEquipoConfirmado() {
  modalEquipoAbierto.value = false
  void comprobarFidelizacionAutomatica()
}

async function logout() {
  tabsStore.clear()
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="layout layout--compacta">
    <aside
      class="sidebar sidebar--compacta"
      :class="{ 'sidebar--desplegada': menuDesplegado }"
      @mouseover="onMenuOver"
      @mouseleave="ocultarAyuda"
    >
      <button
        type="button"
        class="menu-toggle"
        data-ayuda="menu"
        :title="menuDesplegado ? t('menu.toggleCerrar') : t('menu.toggleAbrir')"
        :aria-label="menuDesplegado ? t('menu.toggleCerrar') : t('menu.toggleAbrir')"
        :aria-expanded="menuDesplegado"
        @click="toggleMenu"
      >
        <span class="menu-toggle-icono" aria-hidden="true">
          {{ menuDesplegado ? '«' : '☰' }}
        </span>
        <span v-if="mostrarTexto">{{ t('menu.menu') }}</span>
      </button>
      <div class="sidebar-nav">
      <div v-for="seccion in secciones" :key="seccion.id" class="seccion">
        <button
          type="button"
          class="brand"
          :class="{
            open: seccionesAbiertas[seccion.id],
            active: seccion.id === 'mantenimiento' ? mantenimientoActivo : seccion.activa,
            disabled: !seccion.habilitado,
          }"
          :disabled="!seccion.habilitado"
          :data-ayuda="seccion.id"
          :title="seccion.habilitado ? tx(seccion.id, seccion.titulo) : `${tx(seccion.id, seccion.titulo)} — ${t('menu.sinPermiso')}`"
          @click="seccion.habilitado && toggleSeccion(seccion.id)"
        >
          <ToolIcon :name="seccion.icono" class="brand-icono" />
          <span v-if="mostrarTexto">{{ tx(seccion.id, seccion.titulo) }}</span>
          <span v-if="mostrarTexto && seccion.id !== 'novedades'" class="chevron">
            {{ seccionesAbiertas[seccion.id] ? '▾' : '▸' }}
          </span>
        </button>

        <!-- Mantenimiento: submenu completo -->
        <nav v-if="seccion.id === 'mantenimiento'" v-show="seccionesAbiertas.mantenimiento">
          <template v-for="item in menuMantenimiento" :key="item.slug">
            <RouterLink
              v-if="item.habilitado"
              :to="item.ruta"
              class="nav-link"
              :data-ayuda="item.slug"
              @click="cerrarMenu"
            >
              {{ tx(item.slug, item.titulo) }}
            </RouterLink>
            <span v-else class="nav-link disabled" :data-ayuda="item.slug" :title="t('menu.sinPermiso')">{{
              tx(item.slug, item.titulo)
            }}</span>
          </template>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: articulosActivo, open: articulosAbierto }"
              data-ayuda="grupo-articulos"
              @click="toggleArticulos"
            >
              <span>{{ t('menu.grupo-articulos') }}</span>
              <span class="chevron">{{ articulosAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="articulosAbierto" class="nav-children">
              <template v-for="item in articulosItems" :key="item.slug">
                <RouterLink
                  v-if="item.habilitado"
                  :to="item.ruta"
                  class="nav-link nav-child"
                  :data-ayuda="item.slug"
                  @click="cerrarMenu"
                >
                  {{ tx(item.slug, item.titulo) }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" :data-ayuda="item.slug" :title="t('menu.sinPermiso')">{{
                  tx(item.slug, item.titulo)
                }}</span>
              </template>
            </div>
          </div>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: clientesActivo, open: clientesAbierto }"
              data-ayuda="grupo-clientes"
              @click="toggleClientes"
            >
              <span>{{ t('menu.grupo-clientes') }}</span>
              <span class="chevron">{{ clientesAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="clientesAbierto" class="nav-children">
              <template v-for="item in clientesItems" :key="item.slug">
                <RouterLink
                  v-if="item.habilitado"
                  :to="item.ruta"
                  class="nav-link nav-child"
                  :data-ayuda="item.slug"
                  @click="cerrarMenu"
                >
                  {{ tx(item.slug, item.titulo) }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" :data-ayuda="item.slug" :title="t('menu.sinPermiso')">{{
                  tx(item.slug, item.titulo)
                }}</span>
              </template>
            </div>
          </div>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: proveedoresActivo, open: proveedoresAbierto }"
              data-ayuda="grupo-proveedores"
              @click="toggleProveedores"
            >
              <span>{{ t('menu.grupo-proveedores') }}</span>
              <span class="chevron">{{ proveedoresAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="proveedoresAbierto" class="nav-children">
              <template v-for="item in proveedoresItems" :key="item.slug">
                <RouterLink
                  v-if="item.habilitado"
                  :to="item.ruta"
                  class="nav-link nav-child"
                  :data-ayuda="item.slug"
                  @click="cerrarMenu"
                >
                  {{ tx(item.slug, item.titulo) }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" :data-ayuda="item.slug" :title="t('menu.sinPermiso')">{{
                  tx(item.slug, item.titulo)
                }}</span>
              </template>
            </div>
          </div>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: puestosActivo, open: puestosAbierto }"
              data-ayuda="grupo-puestos"
              @click="togglePuestos"
            >
              <span>{{ t('menu.grupo-puestos') }}</span>
              <span class="chevron">{{ puestosAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="puestosAbierto" class="nav-children">
              <template v-for="item in puestosItems" :key="item.slug">
                <RouterLink
                  v-if="item.habilitado"
                  :to="item.ruta"
                  class="nav-link nav-child"
                  :data-ayuda="item.slug"
                  @click="cerrarMenu"
                >
                  {{ tx(item.slug, item.titulo) }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" :data-ayuda="item.slug" :title="t('menu.sinPermiso')">{{
                  tx(item.slug, item.titulo)
                }}</span>
              </template>
            </div>
          </div>
        </nav>

        <!-- Compras: submenus del modulo -->
        <nav v-else-if="seccion.id === 'compras'" v-show="seccionesAbiertas.compras">
          <template v-for="item in comprasItems" :key="item.id">
            <RouterLink
              v-if="item.habilitado"
              :to="item.ruta"
              class="nav-link nav-child"
              :class="{
                active:
                  route.path === item.ruta || route.path.startsWith(item.ruta + '/'),
              }"
              :data-ayuda="item.id"
              @click="cerrarMenu"
            >
              {{ tx(item.id, item.titulo) }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" :data-ayuda="item.id" :title="t('menu.sinPermiso')">{{
              tx(item.id, item.titulo)
            }}</span>
          </template>
        </nav>

        <!-- Etiquetas: cola de impresión -->
        <nav v-else-if="seccion.id === 'etiquetas'" v-show="seccionesAbiertas.etiquetas">
          <template v-for="item in etiquetasItems" :key="item.id">
            <RouterLink
              v-if="item.habilitado"
              :to="item.ruta"
              class="nav-link nav-child"
              :class="{
                active:
                  route.path === item.ruta || route.path.startsWith(item.ruta + '/'),
              }"
              :data-ayuda="item.id"
              @click="cerrarMenu"
            >
              {{ tx(item.id, item.titulo) }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" :data-ayuda="item.id" :title="t('menu.sinPermiso')">{{
              tx(item.id, item.titulo)
            }}</span>
          </template>
        </nav>

        <!-- Ventas: submenus del modulo -->
        <nav v-else-if="seccion.id === 'ventas'" v-show="seccionesAbiertas.ventas">
          <template v-for="item in ventasItems" :key="item.id">
            <RouterLink
              v-if="item.habilitado"
              :to="item.ruta"
              class="nav-link nav-child"
              :class="{ active: route.path === item.ruta || (item.ruta !== '/ventas' && route.path.startsWith(item.ruta + '/')) }"
              :data-ayuda="item.id"
              @click="cerrarMenu"
            >
              {{ tx(item.id, item.titulo) }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" :data-ayuda="item.id" :title="t('menu.sinPermiso')">{{
              tx(item.id, item.titulo)
            }}</span>
          </template>
        </nav>

        <!-- Facturacion: submenus del modulo -->
        <nav v-else-if="seccion.id === 'facturacion'" v-show="seccionesAbiertas.facturacion">
          <template v-for="item in facturacionItems" :key="item.id">
            <RouterLink
              v-if="item.habilitado"
              :to="item.ruta"
              class="nav-link nav-child"
              :class="{ active: route.path === item.ruta || route.path.startsWith(item.ruta + '/') }"
              :data-ayuda="item.id"
              @click="cerrarMenu"
            >
              {{ tx(item.id, item.titulo) }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" :data-ayuda="item.id" :title="t('menu.sinPermiso')">{{
              tx(item.id, item.titulo)
            }}</span>
          </template>
        </nav>

        <!-- TPV: acceso directo a venta táctil -->
        <nav v-else-if="seccion.id === 'tpv'" v-show="seccionesAbiertas.tpv">
          <RouterLink
            v-if="seccion.habilitado"
            to="/tpv"
            class="nav-link nav-child"
            data-ayuda="tpv-venta"
            :class="{ active: route.path === '/tpv' }"
            @click="cerrarMenu"
          >
            {{ t('menu.tpv-venta') }}
          </RouterLink>
          <span v-else class="nav-link nav-child disabled" data-ayuda="tpv-venta" :title="t('menu.sinPermiso')">{{
            t('menu.tpv-venta')
          }}</span>
          <RouterLink
            v-if="seccion.habilitado"
            to="/tpv/consulta"
            class="nav-link nav-child"
            data-ayuda="consulta-tpv"
            :class="{ active: route.path === '/tpv/consulta' }"
            @click="cerrarMenu"
          >
            {{ t('menu.consulta-tpv') }}
          </RouterLink>
        </nav>

        <!-- Inventario: listados de stock (legacy) -->
        <nav v-else-if="seccion.id === 'inventario'" v-show="seccionesAbiertas.inventario">
          <template v-for="item in inventarioItems" :key="item.id">
            <RouterLink
              v-if="item.habilitado"
              :to="item.ruta"
              class="nav-link nav-child"
              :class="{
                active:
                  route.path === item.ruta ||
                  (item.ruta === '/listados/stock' && route.path.startsWith('/listados/stock/')),
              }"
              :data-ayuda="item.id"
              @click="cerrarMenu"
            >
              {{ tx(item.id, item.titulo) }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" :data-ayuda="item.id" :title="t('menu.sinPermiso')">{{
              tx(item.id, item.titulo)
            }}</span>
          </template>
        </nav>

        <!-- Listados: hub de informes -->
        <nav v-else-if="seccion.id === 'listados'" v-show="seccionesAbiertas.listados">
          <RouterLink
            v-if="seccion.habilitado"
            to="/listados"
            class="nav-link nav-child"
            :class="{ active: route.path === '/listados' || route.path.startsWith('/listados/') }"
            data-ayuda="catalogo-informes"
            @click="cerrarMenu"
          >
            {{ t('menu.catalogo-informes') }}
          </RouterLink>
          <span v-else class="nav-link nav-child disabled" data-ayuda="catalogo-informes" :title="t('menu.sinPermiso')">{{
            t('menu.catalogo-informes')
          }}</span>
        </nav>

        <!-- Resto de secciones: placeholder hasta completar -->
        <nav v-else v-show="seccionesAbiertas[seccion.id]" class="nav-placeholder">
          <RouterLink :to="seccion.ruta" class="nav-link nav-child" :data-ayuda="seccion.id" @click="cerrarMenu">
            {{ t('menu.enConstruccion', { nombre: tx(seccion.id, seccion.titulo) }) }}
          </RouterLink>
        </nav>
      </div>
      </div>

      <div class="sidebar-footer">
        <RouterLink
          to="/configuracion"
          class="btn-config"
          data-ayuda="configuracion"
          :title="t('menu.configuracion')"
          @click="cerrarMenu"
        >
          <ToolIcon name="config" />
          <span v-if="mostrarTexto">{{ t('menu.configuracion') }}</span>
        </RouterLink>
        <button type="button" class="btn-salir" data-ayuda="salir" :title="t('menu.salir')" @click="logout">
          <ToolIcon name="salir" />
          <span v-if="mostrarTexto">{{ t('menu.salir') }}</span>
        </button>
      </div>
    </aside>
    <!-- Cierra el menu desplegado sin tener que volver a pulsar el icono. -->
    <div
      v-if="menuDesplegado"
      class="menu-backdrop"
      @click="cerrarMenu"
    ></div>

    <div class="main">
      <header class="topbar">
        <span class="topbar-titulo">{{ tituloPantalla }}</span>
        <span class="topbar-derecha">
          <select
            v-if="puestoContexto.tiendas.length > 1"
            class="topbar-tienda"
            :value="puestoContexto.empresaCodigo ?? ''"
            :title="t('topbar.tienda')"
            @change="onCambiarTienda"
          >
            <option v-for="tienda in puestoContexto.tiendas" :key="tienda.tienda" :value="tienda.tienda">
              {{ tienda.tiendaNombre || tienda.tienda }}
            </option>
          </select>
          <span v-else-if="etiquetaTienda" class="topbar-tienda" :title="`Tienda ${etiquetaTienda}`">
            {{ etiquetaTienda }}
          </span>
          <button
            v-if="carpetaStore.ruta"
            type="button"
            class="topbar-carpeta"
            :title="t('topbar.carpeta')"
            :aria-label="t('topbar.carpeta')"
            @click="modalCarpeta = true"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z" />
            </svg>
          </button>
          <span v-if="nombreBaseDatos" class="topbar-bd" :title="t('topbar.baseDatos', { nombre: nombreBaseDatos })">
            {{ nombreBaseDatos }}
          </span>
          <span class="topbar-usuario">
            {{ auth.usuario?.nombre ?? 'Usuario' }}<template v-if="etiquetaRol"> · {{ etiquetaRol }}</template>
          </span>
        </span>
      </header>
      <AppTabs />
      <main class="content" :class="{ 'content--flush': route.meta.contentFlush }">
        <RouterView v-slot="{ Component, route: r }">
          <KeepAlive :key="puestoContexto.empresaCodigo ?? ''" :max="8">
            <component :is="Component" :key="claveVistaCacheada(r)" />
          </KeepAlive>
        </RouterView>
      </main>
    </div>

    <CarpetaCompartidaModal
      :open="modalCarpeta && !!carpetaStore.ruta"
      :ruta="carpetaStore.ruta"
      @cerrar="modalCarpeta = false"
    />
    <PuestoEquipoModal
      :open="modalEquipoAbierto"
      :obligatorio="!puestoContexto.configurado"
      @cerrar="modalEquipoAbierto = false"
      @confirmado="onEquipoConfirmado"
    />
    <Teleport to="body">
      <div
        v-if="ayudaVisible"
        class="ayuda-flotante"
        role="tooltip"
        :style="{ top: `${ayudaY}px`, left: `${ayudaX}px` }"
      >
        {{ ayudaTexto }}
      </div>
    </Teleport>
    <EsperaModal
      :open="generandoFidelizacion"
      :titulo="t('fidelizacion.titulo')"
      :mensaje="t('fidelizacion.generando')"
    />
    <ConfirmDialog
      :open="!!avisoFidelizacion"
      :title="t('fidelizacion.titulo')"
      :message="avisoFidelizacion"
      :confirm-label="t('comun.aceptar')"
      hide-cancel
      :danger="false"
      @confirm="avisoFidelizacion = ''"
      @cancel="avisoFidelizacion = ''"
    />
  </div>
</template>

<style scoped>
.layout {
  display: grid;
  /* En rem para que acompañe a la escala de interfaz (`--escala-ui`). */
  grid-template-columns: 15rem 1fr;
  height: calc(100vh - var(--alto-banda, 0px));
  overflow: hidden;
  align-items: stretch;
  position: relative;
}

/* TPV: la rejilla solo reserva la franja de iconos; el menu desplegado flota. */
.layout--compacta {
  grid-template-columns: 3.25rem 1fr;
}

.sidebar {
  height: calc(100vh - var(--alto-banda, 0px));
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--tema-sidebar);
  color: var(--tema-texto-sidebar);
  padding: 1rem;
  box-sizing: border-box;
}

.sidebar--compacta {
  padding: 0.4rem 0.3rem;
  width: 3.25rem;
}

.sidebar--compacta.sidebar--desplegada {
  position: absolute;
  top: 0;
  left: 0;
  z-index: 60;
  width: 15rem;
  padding: 1rem;
  box-shadow: 4px 0 16px rgba(0, 0, 0, 0.45);
}

.menu-toggle {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 0.55rem;
  width: 100%;
  min-height: 2.75rem;
  margin: 0 0 0.45rem;
  padding: 0.45rem 0.55rem;
  border: 1px solid var(--tema-sidebar-boton-borde);
  border-radius: 8px;
  background: var(--tema-sidebar-boton);
  color: var(--tema-texto-sidebar);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
  flex-shrink: 0;
}

.menu-toggle:hover {
  background: var(--tema-sidebar-hover);
  border-color: var(--tema-sidebar-boton-borde);
}

.menu-toggle-icono {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.4rem;
  font-size: 1.45rem;
  line-height: 1;
  flex-shrink: 0;
}

.sidebar--compacta:not(.sidebar--desplegada) .menu-toggle {
  justify-content: center;
  padding: 0.45rem 0;
}

.menu-backdrop {
  position: absolute;
  inset: 0;
  z-index: 50;
  background: rgba(0, 0, 0, 0.35);
}

.sidebar-nav {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.btn-config,
.btn-salir {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--tema-sidebar-boton-borde);
  border-radius: 8px;
  background: var(--tema-sidebar-boton);
  color: var(--tema-texto-sidebar);
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
  flex-shrink: 0;
  text-decoration: none;
  box-sizing: border-box;
}

.sidebar-footer {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-top: 0.75rem;
  flex-shrink: 0;
}

.btn-config:hover,
.btn-config.router-link-active {
  background: var(--tema-sidebar-activo);
  border-color: var(--tema-acento);
}

.btn-salir:hover {
  background: #7f1d1d;
  border-color: #b91c1c;
}

.seccion {
  margin-bottom: 0.35rem;
}

.brand {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: flex-start;
  gap: 0.5rem;
  font-weight: 700;
  margin-bottom: 0.35rem;
  background: transparent;
  padding: 0.35rem 0.5rem;
  border: none;
  border-radius: 8px;
  color: inherit;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
  text-align: left;
}

.brand:hover,
.brand.open {
  background: var(--tema-sidebar-hover);
}

.brand.active:not(.open) {
  background: var(--tema-sidebar-activo);
}

.brand.disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.brand-icono {
  width: 1.15rem;
  height: 1.15rem;
}

/* Franja recogida: iconos centrados y area tactil de 44px. */
.sidebar--compacta:not(.sidebar--desplegada) .brand {
  justify-content: center;
  padding: 0.5rem 0;
  min-height: 2.75rem;
}

.sidebar--compacta:not(.sidebar--desplegada) .brand-icono {
  width: 1.4rem;
  height: 1.4rem;
}

.sidebar--compacta:not(.sidebar--desplegada) .btn-config,
.sidebar--compacta:not(.sidebar--desplegada) .btn-salir {
  justify-content: center;
  padding: 0.5rem 0;
  min-height: 2.4rem;
  gap: 0;
}

.sidebar--compacta:not(.sidebar--desplegada) .seccion {
  margin-bottom: 0.2rem;
}

.nav-link {
  display: block;
  padding: 0.5rem 0.75rem;
  border-radius: 8px;
  color: var(--tema-nav);
  margin-bottom: 0.25rem;
  text-decoration: none;
  border: none;
  background: transparent;
  width: 100%;
  text-align: left;
  font: inherit;
  cursor: pointer;
}

.nav-link.router-link-active,
.nav-link.active {
  background: var(--tema-acento);
  color: #fff;
}

.nav-link.disabled {
  opacity: 0.4;
  cursor: not-allowed;
  color: #9ca3af;
  pointer-events: none;
}

.nav-parent {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.nav-parent.open:not(.active) {
  background: var(--tema-sidebar-hover);
}

.chevron {
  font-size: 0.75rem;
  opacity: 0.85;
}

.brand .chevron {
  margin-left: auto;
}

.nav-children,
.nav-placeholder {
  margin: 0.15rem 0 0.35rem 0.5rem;
  padding-left: 0.5rem;
  border-left: 1px solid var(--tema-sidebar-borde);
}

.nav-child {
  font-size: 0.92rem;
  padding: 0.4rem 0.65rem;
}

.main {
  display: grid;
  grid-template-rows: auto auto 1fr;
  min-width: 0;
  min-height: 0;
  height: calc(100vh - var(--alto-banda, 0px));
  overflow: hidden;
}

/* Misma cabecera legacy que la barra de título del TPV. */
.topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.35rem 0.6rem;
  background: linear-gradient(var(--tema-barra-1), var(--tema-barra-2));
  border: 2px outset #f0f0f0;
  color: #fff;
  font-family: 'Segoe UI', Tahoma, sans-serif;
  flex-shrink: 0;
  z-index: 30;
}

.topbar-titulo {
  font-size: 0.9rem;
  font-weight: 700;
}

.topbar-derecha {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  min-width: 0;
}

.topbar-bd,
.topbar-tienda,
.topbar-usuario {
  max-width: 18rem;
  padding: 0.05rem 0.4rem;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.15);
  border: 1px solid var(--tema-borde-barra);
  font-size: 0.8rem;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.topbar-usuario {
  max-width: 22rem;
}

select.topbar-tienda {
  max-width: 16rem;
  color: #0f172a;
  background: #fff;
  cursor: pointer;
}

.topbar-carpeta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.45rem;
  padding: 0;
  border: 1px solid var(--tema-borde-barra);
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.12);
  color: #fbbf24;
  cursor: pointer;
}

.topbar-carpeta:hover {
  background: rgba(255, 255, 255, 0.28);
}

.topbar-carpeta svg {
  width: 1.15rem;
  height: 1.15rem;
  fill: currentColor;
}

.content {
  padding: 1rem;
  overflow-y: auto;
  overscroll-behavior: contain;
  min-height: 0;
}

.content--flush {
  padding: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

</style>
