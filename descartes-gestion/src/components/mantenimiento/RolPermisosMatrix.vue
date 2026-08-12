<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import {
  mantenimientoNavPermisos,
  otrosModulosPermisos,
  type NavPermisoItem,
  type NavPermisoNodo,
} from '@/config/mantenimiento-nav-permisos'
import { ventasNavPermisos } from '@/config/ventas-nav'
import { facturacionNavPermisos } from '@/config/facturacion-nav'
import { comprasNavPermisos } from '@/config/compras-nav'
import { usePermisos } from '@/composables/usePermisos'
import { useAuthStore } from '@/stores/auth'

export interface RolPermisoRow {
  modulo: string
  ver: boolean
  crear: boolean
  editar: boolean
  eliminar: boolean
}

const props = defineProps<{
  rolCodigo: string
}>()

const auth = useAuthStore()
const { puede } = usePermisos()
const filas = ref<RolPermisoRow[]>([])
const loading = ref(false)
const saving = ref(false)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)
const mantenimientoAbierto = ref(false)
const comprasAbierto = ref(false)
const ventasAbierto = ref(false)
const facturacionAbierto = ref(false)

const puedeEditar = computed(() => puede('roles', 'editar'))

const abiertos = reactive<Record<string, boolean>>({})
for (const nodo of mantenimientoNavPermisos) {
  if (nodo.tipo === 'grupo') abiertos[nodo.id] = true
}

const acciones = [
  { key: 'ver' as const, label: 'Ver' },
  { key: 'crear' as const, label: 'Crear' },
  { key: 'editar' as const, label: 'Editar' },
  { key: 'eliminar' as const, label: 'Eliminar' },
]

const porModulo = computed(() => {
  const map = new Map<string, RolPermisoRow>()
  for (const fila of filas.value) map.set(fila.modulo, fila)
  return map
})

function filaDe(modulo: string): RolPermisoRow | undefined {
  return porModulo.value.get(modulo)
}

function toggleGrupo(id: string) {
  abiertos[id] = !abiertos[id]
}

function itemsGrupo(nodo: NavPermisoNodo): NavPermisoItem[] {
  return nodo.tipo === 'grupo' ? nodo.children : []
}

function todosMarcados(modulo: string): boolean {
  const fila = filaDe(modulo)
  if (!fila) return false
  return fila.ver && fila.crear && fila.editar && fila.eliminar
}

function toggleTodos(modulo: string, checked: boolean) {
  const fila = filaDe(modulo)
  if (!fila || !puedeEditar.value) return
  fila.ver = checked
  fila.crear = checked
  fila.editar = checked
  fila.eliminar = checked
}

function grupoTodosMarcados(nodo: NavPermisoNodo): boolean {
  const items = itemsGrupo(nodo)
  if (!items.length) return false
  return items.every((i) => todosMarcados(i.modulo))
}

function toggleGrupoTodos(nodo: NavPermisoNodo, checked: boolean) {
  for (const item of itemsGrupo(nodo)) toggleTodos(item.modulo, checked)
}

async function cargar() {
  if (!props.rolCodigo) return
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const { data } = await api.get(
      `/api/mantenimiento/roles/${encodeURIComponent(props.rolCodigo)}/permisos`
    )
    filas.value = data
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    error.value = err.response?.data?.error ?? 'No se pudieron cargar los permisos'
  } finally {
    loading.value = false
  }
}

async function guardar() {
  if (!puedeEditar.value) return
  saving.value = true
  mensaje.value = null
  error.value = null
  try {
    const { data } = await api.put(
      `/api/mantenimiento/roles/${encodeURIComponent(props.rolCodigo)}/permisos`,
      filas.value
    )
    filas.value = data
    // Si editamos el rol de la sesion actual, refrescar permisos para el menu.
    const rolSesion = String(auth.usuario?.rolCodigo ?? '').trim()
    if (rolSesion !== '' && rolSesion === String(props.rolCodigo).trim()) {
      await auth.fetchMe()
    }
    mensaje.value = 'Permisos guardados'
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string } } }
    error.value = err.response?.data?.error ?? 'Error al guardar permisos'
  } finally {
    saving.value = false
  }
}

onMounted(cargar)
watch(() => props.rolCodigo, cargar)
</script>

<template>
  <fieldset class="form-section matrix">
    <legend>Permisos</legend>

    <div class="matrix-head">
      <button
        v-if="puedeEditar && filas.length"
        type="button"
        class="tool-btn primary"
        :disabled="saving || loading"
        @click="guardar"
      >
        {{ saving ? 'Guardando...' : 'Guardar permisos' }}
      </button>
    </div>

    <p v-if="loading" class="hint">Cargando permisos...</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>
    <p v-if="error" class="err">{{ error }}</p>

    <div v-if="!loading && filas.length" class="menu-panel">
      <button
        type="button"
        class="brand"
        :class="{ open: mantenimientoAbierto }"
        @click="mantenimientoAbierto = !mantenimientoAbierto"
      >
        <span>Mantenimiento</span>
        <span class="chevron">{{ mantenimientoAbierto ? '▾' : '▸' }}</span>
      </button>

      <div v-show="mantenimientoAbierto" class="menu-body">
        <div class="acciones-header">
          <span class="acciones-spacer">Opcion</span>
          <span v-for="acc in acciones" :key="acc.key" class="acc-label">{{ acc.label }}</span>
          <span class="acc-label">Todos</span>
        </div>

        <nav class="nav">
          <template v-for="nodo in mantenimientoNavPermisos" :key="nodo.id">
            <div v-if="nodo.tipo === 'item' && filaDe(nodo.modulo)" class="nav-row">
              <span class="nav-title">{{ nodo.titulo }}</span>
              <div class="checks">
                <label v-for="acc in acciones" :key="acc.key" :title="acc.label">
                  <span class="sr-only">{{ acc.label }}</span>
                  <input
                    v-model="filaDe(nodo.modulo)![acc.key]"
                    type="checkbox"
                    :disabled="!puedeEditar"
                  />
                </label>
                <label title="Marcar / desmarcar todos">
                  <span class="sr-only">Todos</span>
                  <input
                    type="checkbox"
                    :checked="todosMarcados(nodo.modulo)"
                    :disabled="!puedeEditar"
                    @change="toggleTodos(nodo.modulo, ($event.target as HTMLInputElement).checked)"
                  />
                </label>
              </div>
            </div>

            <div v-else-if="nodo.tipo === 'grupo'" class="nav-group">
              <div class="nav-row parent" :class="{ open: abiertos[nodo.id] }">
                <button type="button" class="nav-toggle" @click="toggleGrupo(nodo.id)">
                  <span class="nav-title">{{ nodo.titulo }}</span>
                  <span class="chevron">{{ abiertos[nodo.id] ? '▾' : '▸' }}</span>
                </button>
                <div class="checks">
                  <span v-for="acc in acciones" :key="acc.key" class="acc-placeholder"></span>
                  <label title="Marcar / desmarcar todo el grupo">
                    <span class="sr-only">Todos del grupo</span>
                    <input
                      type="checkbox"
                      :checked="grupoTodosMarcados(nodo)"
                      :disabled="!puedeEditar"
                      @change="
                        toggleGrupoTodos(nodo, ($event.target as HTMLInputElement).checked)
                      "
                    />
                  </label>
                </div>
              </div>

              <div v-show="abiertos[nodo.id]" class="nav-children">
                <div v-for="hijo in nodo.children" :key="hijo.id" class="nav-row child">
                  <template v-if="filaDe(hijo.modulo)">
                    <span class="nav-title">{{ hijo.titulo }}</span>
                    <div class="checks">
                      <label v-for="acc in acciones" :key="acc.key" :title="acc.label">
                        <span class="sr-only">{{ acc.label }}</span>
                        <input
                          v-model="filaDe(hijo.modulo)![acc.key]"
                          type="checkbox"
                          :disabled="!puedeEditar"
                        />
                      </label>
                      <label title="Marcar / desmarcar todos">
                        <span class="sr-only">Todos</span>
                        <input
                          type="checkbox"
                          :checked="todosMarcados(hijo.modulo)"
                          :disabled="!puedeEditar"
                          @change="
                            toggleTodos(hijo.modulo, ($event.target as HTMLInputElement).checked)
                          "
                        />
                      </label>
                    </div>
                  </template>
                </div>
              </div>
            </div>
          </template>
        </nav>

        <div class="otros">
          <div class="otros-title">Otros modulos</div>
          <div v-for="nodo in otrosModulosPermisos" :key="nodo.id" class="nav-row">
            <template v-if="filaDe(nodo.modulo)">
              <span class="nav-title">{{ nodo.titulo }}</span>
              <div class="checks">
                <label v-for="acc in acciones" :key="acc.key" :title="acc.label">
                  <span class="sr-only">{{ acc.label }}</span>
                  <input
                    v-model="filaDe(nodo.modulo)![acc.key]"
                    type="checkbox"
                    :disabled="!puedeEditar"
                  />
                </label>
                <label title="Marcar / desmarcar todos">
                  <span class="sr-only">Todos</span>
                  <input
                    type="checkbox"
                    :checked="todosMarcados(nodo.modulo)"
                    :disabled="!puedeEditar"
                    @change="toggleTodos(nodo.modulo, ($event.target as HTMLInputElement).checked)"
                  />
                </label>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <div v-if="!loading && filas.length" class="menu-panel menu-panel-ventas">
      <button
        type="button"
        class="brand"
        :class="{ open: comprasAbierto }"
        @click="comprasAbierto = !comprasAbierto"
      >
        <span>Compras</span>
        <span class="chevron">{{ comprasAbierto ? '▾' : '▸' }}</span>
      </button>

      <div v-show="comprasAbierto" class="menu-body">
        <div class="acciones-header">
          <span class="acciones-spacer">Opcion</span>
          <span v-for="acc in acciones" :key="acc.key" class="acc-label">{{ acc.label }}</span>
          <span class="acc-label">Todos</span>
        </div>

        <nav class="nav">
          <div v-for="nodo in comprasNavPermisos" :key="nodo.id" class="nav-row">
            <template v-if="filaDe(nodo.modulo)">
              <span class="nav-title">{{ nodo.titulo }}</span>
              <div class="checks">
                <label v-for="acc in acciones" :key="acc.key" :title="acc.label">
                  <span class="sr-only">{{ acc.label }}</span>
                  <input
                    v-model="filaDe(nodo.modulo)![acc.key]"
                    type="checkbox"
                    :disabled="!puedeEditar"
                  />
                </label>
                <label title="Marcar / desmarcar todos">
                  <span class="sr-only">Todos</span>
                  <input
                    type="checkbox"
                    :checked="todosMarcados(nodo.modulo)"
                    :disabled="!puedeEditar"
                    @change="toggleTodos(nodo.modulo, ($event.target as HTMLInputElement).checked)"
                  />
                </label>
              </div>
            </template>
          </div>
        </nav>
      </div>
    </div>

    <div v-if="!loading && filas.length" class="menu-panel menu-panel-ventas">
      <button
        type="button"
        class="brand"
        :class="{ open: ventasAbierto }"
        @click="ventasAbierto = !ventasAbierto"
      >
        <span>Ventas</span>
        <span class="chevron">{{ ventasAbierto ? '▾' : '▸' }}</span>
      </button>

      <div v-show="ventasAbierto" class="menu-body">
        <div class="acciones-header">
          <span class="acciones-spacer">Opcion</span>
          <span v-for="acc in acciones" :key="acc.key" class="acc-label">{{ acc.label }}</span>
          <span class="acc-label">Todos</span>
        </div>

        <nav class="nav">
          <div v-for="nodo in ventasNavPermisos" :key="nodo.id" class="nav-row">
            <template v-if="filaDe(nodo.modulo)">
              <span class="nav-title">{{ nodo.titulo }}</span>
              <div class="checks">
                <label v-for="acc in acciones" :key="acc.key" :title="acc.label">
                  <span class="sr-only">{{ acc.label }}</span>
                  <input
                    v-model="filaDe(nodo.modulo)![acc.key]"
                    type="checkbox"
                    :disabled="!puedeEditar"
                  />
                </label>
                <label title="Marcar / desmarcar todos">
                  <span class="sr-only">Todos</span>
                  <input
                    type="checkbox"
                    :checked="todosMarcados(nodo.modulo)"
                    :disabled="!puedeEditar"
                    @change="toggleTodos(nodo.modulo, ($event.target as HTMLInputElement).checked)"
                  />
                </label>
              </div>
            </template>
          </div>
        </nav>
      </div>
    </div>

    <div v-if="!loading && filas.length" class="menu-panel menu-panel-ventas">
      <button
        type="button"
        class="brand"
        :class="{ open: facturacionAbierto }"
        @click="facturacionAbierto = !facturacionAbierto"
      >
        <span>Facturacion</span>
        <span class="chevron">{{ facturacionAbierto ? '▾' : '▸' }}</span>
      </button>

      <div v-show="facturacionAbierto" class="menu-body">
        <div class="acciones-header">
          <span class="acciones-spacer">Opcion</span>
          <span v-for="acc in acciones" :key="acc.key" class="acc-label">{{ acc.label }}</span>
          <span class="acc-label">Todos</span>
        </div>

        <nav class="nav">
          <div v-for="nodo in facturacionNavPermisos" :key="nodo.id" class="nav-row">
            <template v-if="filaDe(nodo.modulo)">
              <span class="nav-title">{{ nodo.titulo }}</span>
              <div class="checks">
                <label v-for="acc in acciones" :key="acc.key" :title="acc.label">
                  <span class="sr-only">{{ acc.label }}</span>
                  <input
                    v-model="filaDe(nodo.modulo)![acc.key]"
                    type="checkbox"
                    :disabled="!puedeEditar"
                  />
                </label>
                <label title="Marcar / desmarcar todos">
                  <span class="sr-only">Todos</span>
                  <input
                    type="checkbox"
                    :checked="todosMarcados(nodo.modulo)"
                    :disabled="!puedeEditar"
                    @change="toggleTodos(nodo.modulo, ($event.target as HTMLInputElement).checked)"
                  />
                </label>
              </div>
            </template>
          </div>
        </nav>
      </div>
    </div>
  </fieldset>
</template>

<style scoped>
.form-section {
  margin: 0;
  padding: 0.45rem 0.55rem 0.55rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
}

.form-section legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}

.matrix-head {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.45rem;
}

.tool-btn {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
}

.tool-btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.tool-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.menu-panel {
  width: 100%;
  box-sizing: border-box;
}

.menu-panel-ventas {
  margin-top: 0.75rem;
}

.brand {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  font-weight: 600;
  font-size: 0.85rem;
  margin: 0 0 0.35rem;
  padding: 0.35rem 0.45rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #f8fafc;
  color: #334155;
  cursor: pointer;
  font-family: inherit;
  text-align: left;
}

.brand.open {
  background: #e8edf2;
}

.acciones-header {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.35rem 0.45rem;
  color: #64748b;
  font-size: 0.72rem;
  font-weight: 600;
}

.acciones-spacer {
  flex: 1;
  min-width: 0;
  font-weight: 500;
}

.acc-label {
  width: 3.6rem;
  text-align: center;
  flex-shrink: 0;
}

.nav-group {
  margin-bottom: 0.1rem;
}

.nav-row {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.4rem;
  border-radius: 4px;
  color: #334155;
}

.nav-row.parent.open {
  background: #f1f5f9;
}

.nav-row.child {
  padding: 0.25rem 0.35rem;
}

.nav-toggle {
  display: flex;
  flex: 1;
  min-width: 0;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
  border: none;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
  padding: 0;
  text-align: left;
}

.nav-title {
  flex: 1;
  min-width: 0;
  font-size: 0.82rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.nav-row.child .nav-title {
  font-size: 0.8rem;
  color: #475569;
}

.chevron {
  font-size: 0.75rem;
  opacity: 0.85;
  flex-shrink: 0;
}

.checks {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  flex-shrink: 0;
}

.checks label {
  width: 3.6rem;
  display: flex;
  justify-content: center;
  margin: 0;
}

.acc-placeholder {
  width: 3.6rem;
  flex-shrink: 0;
}

.checks input {
  width: 0.95rem;
  height: 0.95rem;
  cursor: pointer;
  accent-color: #2563eb;
}

.checks input:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.nav-children {
  margin: 0.05rem 0 0.25rem 0.55rem;
  padding-left: 0.45rem;
  border-left: 1px solid #cbd5e1;
}

.otros {
  margin-top: 0.65rem;
  padding-top: 0.45rem;
  border-top: 1px solid #e2e8f0;
}

.otros-title {
  font-size: 0.72rem;
  font-weight: 600;
  color: #64748b;
  padding: 0 0.35rem 0.35rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.hint {
  margin: 0.25rem 0;
  color: #64748b;
  font-size: 0.8rem;
}

.ok {
  color: #047857;
  margin: 0.25rem 0;
  font-size: 0.8rem;
}

.err {
  color: #b91c1c;
  margin: 0.25rem 0;
  font-size: 0.8rem;
}
</style>
