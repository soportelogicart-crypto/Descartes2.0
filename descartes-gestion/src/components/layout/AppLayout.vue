<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { articulosMenuItems, esRutaArticulos } from '@/config/articulos-menu'
import { clientesMenuItems, esRutaClientes } from '@/config/clientes-menu'
import { puestosMenuItems, esRutaPuestos } from '@/config/puestos-menu'
import { proveedoresMenuItems, esRutaProveedores } from '@/config/proveedores-menu'
import { ventasMenuItems, esRutaVentas } from '@/config/ventas-nav'
import { facturacionMenuItems, esRutaFacturacion } from '@/config/facturacion-nav'
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

const articulosAbierto = ref(esRutaArticulos(route.path))
const clientesAbierto = ref(esRutaClientes(route.path))
const puestosAbierto = ref(esRutaPuestos(route.path))
const proveedoresAbierto = ref(esRutaProveedores(route.path))
const ventasAbierto = ref(esRutaVentas(route.path))
const facturacionAbierto = ref(esRutaFacturacion(route.path))
const modalEquipoAbierto = ref(false)

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
    // Estructura siempre visible salvo Ventas/Facturacion si no hay submenu permitido.
    habilitado:
      seccion.id === 'ventas'
        ? ventasSeccionVisible.value
        : seccion.id === 'facturacion'
          ? facturacionSeccionVisible.value
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
const ventasActivo = computed(() => esRutaVentas(route.path))
const mantenimientoActivo = computed(() => route.path.startsWith('/mantenimiento'))

watch(
  () => route.fullPath,
  (fullPath) => {
    const path = route.path
    articulosAbierto.value = esRutaArticulos(path)
    clientesAbierto.value = esRutaClientes(path)
    puestosAbierto.value = esRutaPuestos(path)
    proveedoresAbierto.value = esRutaProveedores(path)
    ventasAbierto.value = esRutaVentas(path)
    facturacionAbierto.value = esRutaFacturacion(path)
    if (path.startsWith('/mantenimiento')) seccionesAbiertas.mantenimiento = true
    for (const seccion of menuPrincipalSecciones) {
      if (seccion.id === 'mantenimiento') continue
      if (path === seccion.ruta || path.startsWith(`${seccion.ruta}/`)) {
        seccionesAbiertas[seccion.id] = true
      }
    }
    // Pestañas: cada pantalla consultada queda abierta (máx. 8).
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
  seccionesAbiertas[id] = !seccionesAbiertas[id]
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
  <div class="layout">
    <aside class="sidebar">
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
          :title="seccion.habilitado ? undefined : 'Sin permiso'"
          @click="seccion.habilitado && toggleSeccion(seccion.id)"
        >
          <span>{{ seccion.titulo }}</span>
          <span class="chevron">{{ seccionesAbiertas[seccion.id] ? '▾' : '▸' }}</span>
        </button>

        <!-- Mantenimiento: submenu completo -->
        <nav v-if="seccion.id === 'mantenimiento'" v-show="seccionesAbiertas.mantenimiento">
          <template v-for="item in menuMantenimiento" :key="item.slug">
            <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link">
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
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child">
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
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child">
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
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child">
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
                <RouterLink v-if="item.habilitado" :to="item.ruta" class="nav-link nav-child">
                  {{ item.titulo }}
                </RouterLink>
                <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{
                  item.titulo
                }}</span>
              </template>
            </div>
          </div>
        </nav>

        <!-- Ventas: submenus del modulo -->
        <nav v-else-if="seccion.id === 'ventas'" v-show="seccionesAbiertas.ventas">
          <template v-for="item in ventasItems" :key="item.id">
            <RouterLink
              v-if="item.habilitado"
              :to="item.ruta"
              class="nav-link nav-child"
              :class="{ active: route.path === item.ruta || (item.ruta !== '/ventas' && route.path.startsWith(item.ruta + '/')) }"
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
            >
              {{ item.titulo }}
            </RouterLink>
            <span v-else class="nav-link nav-child disabled" title="Sin permiso">{{ item.titulo }}</span>
          </template>
        </nav>

        <!-- Resto de secciones: placeholder hasta completar -->
        <nav v-else v-show="seccionesAbiertas[seccion.id]" class="nav-placeholder">
          <RouterLink :to="seccion.ruta" class="nav-link nav-child">
            {{ seccion.titulo }} (en construccion)
          </RouterLink>
        </nav>
      </div>
      </div>

      <button type="button" class="btn-salir" title="Salir" @click="logout">
        <ToolIcon name="salir" />
        <span>Salir</span>
      </button>
    </aside>
    <div class="main">
      <header class="topbar">
        <span>{{ auth.usuario?.nombre ?? 'Usuario' }}</span>
      </header>
      <AppTabs />
      <main class="content">
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
  grid-template-columns: 240px 1fr;
  height: 100vh;
  overflow: hidden;
  align-items: stretch;
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

.sidebar-nav {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.btn-salir {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  margin-top: 0.75rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid #4b5563;
  border-radius: 8px;
  background: #1f2937;
  color: #f9fafb;
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
  flex-shrink: 0;
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
  justify-content: space-between;
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

.topbar {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 0.75rem 1rem;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  flex-shrink: 0;
  z-index: 30;
}

.content {
  padding: 1rem;
  overflow-y: auto;
  overscroll-behavior: contain;
  min-height: 0;
}
</style>
