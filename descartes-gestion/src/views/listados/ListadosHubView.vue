<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import {
  LISTADOS_CATALOGO,
  LISTADOS_CATEGORIAS,
  itemCoincideBusqueda,
  itemHabilitadoEnCatalogo,
  informesEnCatalogoHub,
  normalizarTextoBusqueda,
  type ListadoCatalogoItem,
  type ListadosCategoriaId,
} from '@/config/listados-nav'
import { usePermisos } from '@/composables/usePermisos'
import { useListadosRecientes } from '@/composables/useListadosRecientes'

const { t } = useI18n()
const router = useRouter()

function tituloDe(item: ListadoCatalogoItem): string {
  return t(`informes.items.${item.id}.titulo`)
}

function descDe(item: ListadoCatalogoItem): string {
  return t(`informes.items.${item.id}.descripcion`)
}

function coincide(item: ListadoCatalogoItem): boolean {
  if (itemCoincideBusqueda(item, busqueda.value)) return true
  const q = normalizarTextoBusqueda(busqueda.value.trim())
  if (!q) return false
  return normalizarTextoBusqueda(`${tituloDe(item)} ${descDe(item)}`).includes(q)
}
const { puede } = usePermisos()
const { idsRecientes, registrarReciente } = useListadosRecientes()

const busqueda = ref('')

function itemHabilitado(item: ListadoCatalogoItem): boolean {
  return itemHabilitadoEnCatalogo(item, puede)
}

/** Informes siempre listados; accesos solo si tienen permiso (o se ocultan). */
const catalogoEnHub = computed(() => {
  const informes = informesEnCatalogoHub()
  const accesos = LISTADOS_CATALOGO.filter(
    (i) => i.kind === 'acceso' && i.disponible && itemHabilitado(i),
  )
  return [...informes, ...accesos]
})

const catalogoFiltrado = computed(() => {
  const q = busqueda.value
  return catalogoEnHub.value.filter((item) => coincide(item))
})

const recientes = computed(() => {
  const map = new Map(catalogoEnHub.value.map((i) => [i.id, i]))
  return idsRecientes.value
    .map((id) => map.get(id))
    .filter(
      (i): i is ListadoCatalogoItem =>
        !!i && itemHabilitado(i) && coincide(i),
    )
})

function itemsPorCategoria(cat: ListadosCategoriaId) {
  return catalogoFiltrado.value.filter((i) => i.categoria === cat)
}

const categoriasConItems = computed(() =>
  LISTADOS_CATEGORIAS.filter((c) => itemsPorCategoria(c.id).length > 0)
)

function abrir(item: ListadoCatalogoItem) {
  if (!item.disponible || !itemHabilitado(item)) return
  registrarReciente(item.id)
  void router.push(item.ruta)
}

function etiquetaKind(item: ListadoCatalogoItem) {
  return item.kind === 'acceso' ? t('informes.acceso') : t('informes.informe')
}
</script>

<template>
  <section class="listados-hub">
    <header class="cabecera">
      <h2>{{ t('informes.hubTitulo') }}</h2>
      <p class="intro">{{ t('informes.hubIntro') }}</p>
      <label class="buscador">
        <span class="sr-only">{{ t('informes.buscar') }}</span>
        <input
          v-model="busqueda"
          type="search"
          autocomplete="off"
          :placeholder="t('informes.placeholder')"
        />
      </label>
    </header>

    <section v-if="recientes.length && !busqueda.trim()" class="bloque">
      <h3>{{ t('informes.recientes') }}</h3>
      <ul class="recientes">
        <li v-for="item in recientes" :key="item.id">
          <button
            v-if="item.disponible"
            type="button"
            class="link-reciente"
            @click="abrir(item)"
          >
            {{ tituloDe(item) }}
          </button>
          <span v-else class="link-reciente disabled">{{ tituloDe(item) }}</span>
        </li>
      </ul>
    </section>

    <p v-if="catalogoFiltrado.length === 0" class="vacio">
      {{ t('informes.vacio') }}
    </p>

    <section
      v-for="cat in categoriasConItems"
      :key="cat.id"
      class="bloque categoria"
    >
      <h3>{{ t(`informes.categorias.${cat.id}`) }}</h3>
      <div class="tarjetas">
        <button
          v-for="item in itemsPorCategoria(cat.id)"
          :key="item.id"
          type="button"
          class="tarjeta"
          :class="{ disabled: !item.disponible || !itemHabilitado(item) }"
          :disabled="!item.disponible || !itemHabilitado(item)"
          :title="itemHabilitado(item) ? undefined : t('informes.sinPermiso')"
          @click="abrir(item)"
        >
          <div class="tarjeta-top">
            <h4>{{ tituloDe(item) }}</h4>
            <span class="badge" :class="item.kind">{{ etiquetaKind(item) }}</span>
          </div>
          <p>{{ descDe(item) }}</p>
          <span v-if="!item.disponible" class="estado">{{ t('informes.proximamente') }}</span>
          <span v-else-if="!itemHabilitado(item)" class="estado">{{ t('menu.sinPermiso') }}</span>
          <span v-else class="estado open">{{ t('informes.abrir') }}</span>
        </button>
      </div>
    </section>

    <p class="nota">
      {{ t('informes.nota') }}
    </p>
  </section>
</template>

<style scoped>
.listados-hub {
  max-width: 56rem;
}

.cabecera h2 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
}

.intro {
  margin: 0 0 1rem;
  color: #64748b;
  font-size: 0.9rem;
  line-height: 1.45;
}

.buscador input {
  width: 100%;
  max-width: 28rem;
  box-sizing: border-box;
  padding: 0.55rem 0.75rem;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  font-size: 0.95rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

.bloque {
  margin-top: 1.5rem;
}

.bloque h3 {
  margin: 0 0 0.65rem;
  font-size: 1rem;
  color: #334155;
}

.recientes {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.link-reciente {
  border: 1px solid #c5cdd8;
  background: #f8fafc;
  border-radius: 999px;
  padding: 0.35rem 0.85rem;
  font-size: 0.85rem;
  cursor: pointer;
  color: #0f172a;
}

.link-reciente:not(.disabled):hover {
  border-color: #3b82f6;
  background: #fff;
}

.link-reciente.disabled {
  opacity: 0.55;
  cursor: default;
}

.tarjetas {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
}

.tarjeta {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 1rem 1.1rem;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  background: #fff;
  text-align: left;
  color: inherit;
  min-height: 7.5rem;
  box-sizing: border-box;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
  font: inherit;
}

.tarjeta:not(.disabled):hover {
  border-color: #3b82f6;
  box-shadow: 0 1px 4px rgb(15 23 42 / 8%);
}

.tarjeta.disabled {
  opacity: 0.72;
  background: #f8fafc;
  cursor: default;
}

.tarjeta-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
}

.tarjeta h4 {
  margin: 0;
  font-size: 0.98rem;
  line-height: 1.25;
}

.tarjeta p {
  margin: 0;
  flex: 1;
  color: #64748b;
  font-size: 0.85rem;
  line-height: 1.4;
}

.badge {
  flex-shrink: 0;
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.15rem 0.45rem;
  border-radius: 4px;
  background: #e2e8f0;
  color: #475569;
}

.badge.informe {
  background: #dbeafe;
  color: #1d4ed8;
}

.badge.acceso {
  background: #f1f5f9;
  color: #64748b;
}

.estado {
  font-size: 0.75rem;
  font-weight: 600;
  color: #94a3b8;
}

.estado.open {
  color: #0f172a;
}

.vacio {
  margin-top: 1.5rem;
  color: #64748b;
}

.nota {
  margin-top: 2rem;
  font-size: 0.8rem;
  color: #94a3b8;
}
</style>
