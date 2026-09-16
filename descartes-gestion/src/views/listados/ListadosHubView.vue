<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  LISTADOS_CATALOGO,
  LISTADOS_CATEGORIAS,
  itemCoincideBusqueda,
  type ListadoCatalogoItem,
  type ListadosCategoriaId,
} from '@/config/listados-nav'
import { usePermisos } from '@/composables/usePermisos'
import { useListadosRecientes } from '@/composables/useListadosRecientes'

const router = useRouter()
const { puede } = usePermisos()
const { idsRecientes, registrarReciente } = useListadosRecientes()

const busqueda = ref('')

function puedeVerItem(item: ListadoCatalogoItem): boolean {
  return puede(item.modulo, 'ver')
}

const catalogoVisible = computed(() =>
  LISTADOS_CATALOGO.filter((item) => puedeVerItem(item))
)

const catalogoFiltrado = computed(() => {
  const q = busqueda.value
  return catalogoVisible.value.filter((item) => itemCoincideBusqueda(item, q))
})

const recientes = computed(() => {
  const map = new Map(catalogoVisible.value.map((i) => [i.id, i]))
  return idsRecientes.value
    .map((id) => map.get(id))
    .filter((i): i is ListadoCatalogoItem => !!i && itemCoincideBusqueda(i, busqueda.value))
})

function itemsPorCategoria(cat: ListadosCategoriaId) {
  return catalogoFiltrado.value.filter((i) => i.categoria === cat)
}

const categoriasConItems = computed(() =>
  LISTADOS_CATEGORIAS.filter((c) => itemsPorCategoria(c.id).length > 0)
)

function abrir(item: ListadoCatalogoItem) {
  if (!item.disponible) return
  registrarReciente(item.id)
  void router.push(item.ruta)
}

function etiquetaKind(item: ListadoCatalogoItem) {
  return item.kind === 'acceso' ? 'Acceso' : 'Informe'
}
</script>

<template>
  <section class="listados-hub">
    <header class="cabecera">
      <h2>Listados</h2>
      <p class="intro">
        Busque un informe o acceso rápido. Los informes nuevos se generan en pantalla antes de
        imprimir o exportar a Excel.
      </p>
      <label class="buscador">
        <span class="sr-only">Buscar informe</span>
        <input
          v-model="busqueda"
          type="search"
          autocomplete="off"
          placeholder="Buscar informe: stock, abc, iva…"
        />
      </label>
    </header>

    <section v-if="recientes.length && !busqueda.trim()" class="bloque">
      <h3>Recientes</h3>
      <ul class="recientes">
        <li v-for="item in recientes" :key="item.id">
          <button
            v-if="item.disponible"
            type="button"
            class="link-reciente"
            @click="abrir(item)"
          >
            {{ item.titulo }}
          </button>
          <span v-else class="link-reciente disabled">{{ item.titulo }}</span>
        </li>
      </ul>
    </section>

    <p v-if="catalogoFiltrado.length === 0" class="vacio">
      No hay informes que coincidan con la búsqueda o con sus permisos.
    </p>

    <section
      v-for="cat in categoriasConItems"
      :key="cat.id"
      class="bloque categoria"
    >
      <h3>{{ cat.titulo }}</h3>
      <div class="tarjetas">
        <component
          :is="item.disponible ? 'button' : 'div'"
          v-for="item in itemsPorCategoria(cat.id)"
          :key="item.id"
          type="button"
          class="tarjeta"
          :class="{ disabled: !item.disponible }"
          @click="item.disponible ? abrir(item) : undefined"
        >
          <div class="tarjeta-top">
            <h4>{{ item.titulo }}</h4>
            <span class="badge" :class="item.kind">{{ etiquetaKind(item) }}</span>
          </div>
          <p>{{ item.descripcion }}</p>
          <span v-if="!item.disponible" class="estado">Próximamente</span>
          <span v-else class="estado open">Abrir</span>
        </component>
      </div>
    </section>

    <p class="nota">
      ABC de ventas y el diario de facturación siguen en sus módulos; desde aquí se abre la misma
      pantalla.
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
