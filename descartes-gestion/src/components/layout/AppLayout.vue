<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { articulosMenuItems, esRutaArticulos } from '@/config/articulos-menu'
import { clientesMenuItems, esRutaClientes } from '@/config/clientes-menu'
import { puestosMenuItems, esRutaPuestos } from '@/config/puestos-menu'
import { proveedoresMenuItems, esRutaProveedores } from '@/config/proveedores-menu'
import { ventasMenuItems } from '@/config/ventas-nav'
import { facturacionMenuItems } from '@/config/facturacion-nav'
import { comprasMenuItems } from '@/config/compras-nav'
import { etiquetasMenuItems } from '@/config/etiquetas-nav'
import { moduloDeEntradaMenu } from '@/config/mantenimiento-nav-permisos'
import { menuPrincipalSecciones } from '@/config/menu-principal'
import { entidades } from '@/config/entidades'
import { usePermisos } from '@/composables/usePermisos'
import PuestoEquipoModal from '@/components/puestos/PuestoEquipoModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useTabsStore } from '@/stores/tabs'
import AppTabs from '@/components/layout/AppTabs.vue'

const auth = useAuthStore()
const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()
const tabsStore = useTabsStore()
const route = useRoute()
const router = useRouter()

const articulosAbierto = ref(false)
const clientesAbierto = ref(false)
const puestosAbierto = ref(false)
const proveedoresAbierto = ref(false)
const modalEquipoAbierto = ref(false)

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
                  ? puede('listados', 'ver')
                  : seccion.id === 'inventario'
                    ? puede('inventario', 'ver')
                    : true,
    activa:
      seccion.id === 'mantenimiento'
        ? route.path.startsWith('/mantenimiento')
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
        slug !== 'proveedores'
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

const articulosActivo = computed(() => esRutaArticulos(route.path))
const clientesActivo = computed(() => esRutaClientes(route.path))
const puestosActivo = computed(() => esRutaPuestos(route.path))
const proveedoresActivo = computed(() => esRutaProveedores(route.path))
const mantenimientoActivo = computed(() => route.path.startsWith('/mantenimiento'))

/** Cabecera legacy: nombre de la pantalla actual, como la barra del TPV. */
const tituloPantalla = computed(() =>
  typeof route.meta.titulo === 'string' && route.meta.titulo.trim() !== ''
    ? route.meta.titulo
    : 'Descartes Gestion'
)

watch(
  () => route.fullPath,
  (fullPath) => {
    // Pestañas: cada pantalla consultada queda abierta (máx. 8).
    // El menu lateral no se reabre automaticamente al navegar.
    if (auth.cargado) {
      const titulo = typeof route.meta.titulo === 'string' ? route.meta.titulo : undefined
      tabsStore.openOrActivate(fullPath, titulo)
    }
  },
  { immediate: true }
)

watch(
  () => auth.cargado,
  async (cargado) => {
    if (!cargado) return
    if (puestoContexto.equipoId) {
      await puestoContexto.hydrateFromApi()
    }
    if (!puestoContexto.configurado) {
      modalEquipoAbierto.value = true
    }
  },
  { immediate: true }
)

function toggleSeccion(id: string) {
  const seccion = secciones.value.find((s) => s.id === id)
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
    >
      <button
        type="button"
        class="menu-toggle"
        :title="menuDesplegado ? 'Recoger menú' : 'Desplegar menú'"
        :aria-label="menuDesplegado ? 'Recoger menú' : 'Desplegar menú'"
        :aria-expanded="menuDesplegado"
        @click="toggleMenu"
      >
        <span class="menu-toggle-icono" aria-hidden="true">
          {{ menuDesplegado ? '«' : '☰' }}
        </span>
        <span v-if="mostrarTexto">MENÚ</span>
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
          :title="seccion.habilitado ? seccion.titulo : `${seccion.titulo} — sin permiso`"
          @click="seccion.habilitado && toggleSeccion(seccion.id)"
        >
          <ToolIcon :name="seccion.icono" class="brand-icono" />
          <span v-if="mostrarTexto">{{ seccion.titulo }}</span>
          <span v-if="mostrarTexto" class="chevron">
            {{ seccionesAbiertas[seccion.id] ? '▾' : '▸' }}
          </span>
        </button>

        <!-- Mantenimiento: submenu completo -->
        <nav v-if="seccion.id === 'mantenimiento'" v-show="seccionesAbiertas.mantenimiento">
          <template v-for="item in menuMantenimiento" :key="item.slug">
            <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link" @click="cerrarMenu">
              {{ item.titulo }}
            </RouterLink>
            <span v-else class="nav-link disabled" title="Sin permiso">{{ item.titulo }}</span>
          </template>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: articulosActivo, open: articulosAbierto }"
              @click="toggleArticulos"
            >
              <span>Articulos</span>
              <span class="chevron">{{ articulosAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="articulosAbierto" class="nav-children">
              <template v-for="item in articulosItems" :key="item.slug">
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child" @click="cerrarMenu">
                  {{ item.titulo }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{
                  item.titulo
                }}</span>
              </template>
            </div>
          </div>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: clientesActivo, open: clientesAbierto }"
              @click="toggleClientes"
            >
              <span>Clientes</span>
              <span class="chevron">{{ clientesAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="clientesAbierto" class="nav-children">
              <template v-for="item in clientesItems" :key="item.slug">
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child" @click="cerrarMenu">
                  {{ item.titulo }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{
                  item.titulo
                }}</span>
              </template>
            </div>
          </div>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: proveedoresActivo, open: proveedoresAbierto }"
              @click="toggleProveedores"
            >
              <span>Proveedores</span>
              <span class="chevron">{{ proveedoresAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="proveedoresAbierto" class="nav-children">
              <template v-for="item in proveedoresItems" :key="item.slug">
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child" @click="cerrarMenu">
                  {{ item.titulo }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{
                  item.titulo
                }}</span>
              </template>
            </div>
          </div>

          <div class="nav-group">
            <button
              type="button"
              class="nav-link nav-parent"
              :class="{ active: puestosActivo, open: puestosAbierto }"
              @click="togglePuestos"
            >
              <span>Puestos de trabajo</span>
              <span class="chevron">{{ puestosAbierto ? '▾' : '▸' }}</span>
            </button>
            <div v-show="puestosAbierto" class="nav-children">
              <template v-for="item in puestosItems" :key="item.slug">
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child" @click="cerrarMenu">
                  {{ item.titulo }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{
                  item.titulo
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
              @click="cerrarMenu"
            >
              {{ item.titulo }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{
              item.titulo
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
              @click="cerrarMenu"
            >
              {{ item.titulo }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{
              item.titulo
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
              @click="cerrarMenu"
            >
              {{ item.titulo }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{ item.titulo }}</span>
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
              @click="cerrarMenu"
            >
              {{ item.titulo }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{ item.titulo }}</span>
          </template>
        </nav>

        <!-- TPV: acceso directo a venta táctil -->
        <nav v-else-if="seccion.id === 'tpv'" v-show="seccionesAbiertas.tpv">
          <RouterLink
            v-if="seccion.habilitado"
            to="/tpv"
            class="nav-link nav-child"
            :class="{ active: route.path === '/tpv' }"
            @click="cerrarMenu"
          >
            Venta táctil
          </RouterLink>
          <span v-else class="nav-link nav-child disabled" title="Sin permiso">Venta táctil</span>
        </nav>

        <!-- Listados: hub de informes -->
        <nav v-else-if="seccion.id === 'listados'" v-show="seccionesAbiertas.listados">
          <RouterLink
            v-if="seccion.habilitado"
            to="/listados"
            class="nav-link nav-child"
            :class="{ active: route.path === '/listados' || route.path.startsWith('/listados/') }"
            @click="cerrarMenu"
          >
            Catálogo de informes
          </RouterLink>
          <span v-else class="nav-link nav-child disabled" title="Sin permiso">Catálogo de informes</span>
        </nav>

        <!-- Resto de secciones: placeholder hasta completar -->
        <nav v-else v-show="seccionesAbiertas[seccion.id]" class="nav-placeholder">
          <RouterLink :to="seccion.ruta" class="nav-link nav-child" @click="cerrarMenu">
            {{ seccion.titulo }} (en construccion)
          </RouterLink>
        </nav>
      </div>
      </div>

      <div class="sidebar-footer">
        <RouterLink to="/configuracion" class="btn-config" title="Configuracion" @click="cerrarMenu">
          <ToolIcon name="config" />
          <span v-if="mostrarTexto">Configuracion</span>
        </RouterLink>
        <button type="button" class="btn-salir" title="Salir" @click="logout">
          <ToolIcon name="salir" />
          <span v-if="mostrarTexto">Salir</span>
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
        <span class="topbar-usuario">{{ auth.usuario?.nombre ?? 'Usuario' }}</span>
      </header>
      <AppTabs />
      <main class="content" :class="{ 'content--flush': route.meta.contentFlush }">
        <RouterView v-slot="{ Component, route: r }">
          <KeepAlive :max="8">
            <component :is="Component" :key="r.fullPath" />
          </KeepAlive>
        </RouterView>
      </main>
    </div>

    <PuestoEquipoModal
      :open="modalEquipoAbierto"
      :obligatorio="!puestoContexto.configurado"
      @cerrar="modalEquipoAbierto = false"
      @confirmado="onEquipoConfirmado"
    />
  </div>
</template>

<style scoped>
.layout {
  display: grid;
  /* En rem para que acompañe a la escala de interfaz (`--escala-ui`). */
  grid-template-columns: 15rem 1fr;
  height: 100vh;
  overflow: hidden;
  align-items: stretch;
  position: relative;
}

/* TPV: la rejilla solo reserva la franja de iconos; el menu desplegado flota. */
.layout--compacta {
  grid-template-columns: 3.25rem 1fr;
}

.sidebar {
  height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: #111827;
  color: #f9fafb;
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
  border: 1px solid #4b5563;
  border-radius: 8px;
  background: #1f2937;
  color: #f9fafb;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
  flex-shrink: 0;
}

.menu-toggle:hover {
  background: #374151;
  border-color: #6b7280;
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
  border: 1px solid #4b5563;
  border-radius: 8px;
  background: #1f2937;
  color: #f9fafb;
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
  background: #1e3a5f;
  border-color: #3b82f6;
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
  background: #111827;
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
  background: #1f2937;
}

.brand.active:not(.open) {
  background: #1e3a5f;
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
  color: #e5e7eb;
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
  background: #2563eb;
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
  background: #1f2937;
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
  border-left: 1px solid #374151;
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
  height: 100vh;
  overflow: hidden;
}

/* Misma cabecera legacy que la barra de título del TPV. */
.topbar {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.75rem;
  padding: 0.35rem 0.6rem;
  background: linear-gradient(#00309c, #000060);
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

.topbar-usuario {
  max-width: 16rem;
  padding: 0.05rem 0.4rem;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.15);
  border: 1px solid #7a8ec0;
  font-size: 0.8rem;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
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
