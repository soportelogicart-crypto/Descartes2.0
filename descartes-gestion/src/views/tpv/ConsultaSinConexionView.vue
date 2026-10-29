<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { buscarArticulosTpv, buscarClientesTpv } from '@/api/tpv'
import { extractApiError } from '@/composables/extractApiError'
import { useEstadoConexion } from '@/offline/estadoConexion'
import type { TpvArticuloPrecio, TpvCliente } from '@/types/tpv'

const { estado, sinConexion } = useEstadoConexion()

const pestana = ref<'articulos' | 'clientes'>('articulos')
const texto = ref('')
const tarifa = ref(1)
const articulos = ref<TpvArticuloPrecio[]>([])
const clientes = ref<TpvCliente[]>([])
const cargando = ref(false)
const error = ref('')
let ticket = 0
let timer: number | null = null

const origen = computed(() => {
  if (!sinConexion.value) return 'Datos del servidor.'
  if (!estado.ultimaFoto) return 'Sin datos locales en este puesto.'
  const d = new Date(estado.ultimaFoto)
  return `Datos locales del ${d.toLocaleDateString()} a las ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}: pueden no estar al día.`
})

async function buscar() {
  const q = texto.value.trim()
  const mio = ++ticket
  error.value = ''
  if (q.length < 2) {
    articulos.value = []
    clientes.value = []
    return
  }
  cargando.value = true
  try {
    if (pestana.value === 'articulos') {
      const r = await buscarArticulosTpv(q, { tarifa: tarifa.value, limite: 100 })
      if (mio === ticket) articulos.value = r
    } else {
      const r = await buscarClientesTpv(q, 100)
      if (mio === ticket) clientes.value = r
    }
  } catch (e) {
    if (mio === ticket) error.value = extractApiError(e)
  } finally {
    if (mio === ticket) cargando.value = false
  }
}

watch([texto, tarifa, pestana], () => {
  if (timer) window.clearTimeout(timer)
  timer = window.setTimeout(() => void buscar(), 250)
})

function precio(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <section class="consulta">
    <header class="consulta__cab">
      <div class="pestanas">
        <button type="button" :class="{ activa: pestana === 'articulos' }" @click="pestana = 'articulos'">
          Artículos
        </button>
        <button type="button" :class="{ activa: pestana === 'clientes' }" @click="pestana = 'clientes'">
          Clientes
        </button>
      </div>
      <input
        v-model="texto"
        class="consulta__buscar"
        type="search"
        autofocus
        :placeholder="pestana === 'articulos' ? 'Código, EAN o descripción' : 'Código, nombre, NIF o teléfono'"
      />
      <label v-if="pestana === 'articulos'" class="consulta__tarifa">
        Tarifa
        <select v-model.number="tarifa">
          <option v-for="t in 9" :key="t" :value="t">{{ t }}</option>
        </select>
      </label>
    </header>
    <p class="consulta__origen" :class="{ 'consulta__origen--local': sinConexion }">{{ origen }}</p>
    <p v-if="error" class="consulta__error">{{ error }}</p>
    <p v-else-if="cargando" class="consulta__nota">Buscando…</p>
    <p v-else-if="texto.trim().length < 2" class="consulta__nota">Escriba al menos 2 caracteres.</p>

    <table v-if="pestana === 'articulos' && articulos.length" class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Descripción</th>
          <th>Familia</th>
          <th class="num">IVA</th>
          <th class="num">Precio</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="a in articulos" :key="a.codigo" :class="{ bloqueado: a.bloqueado }">
          <td>{{ a.codigo }}</td>
          <td>{{ a.descripcion }}</td>
          <td>{{ a.familiaDescripcion || a.familiaCodigo || '' }}</td>
          <td class="num">{{ a.iva ?? '' }}</td>
          <td class="num">{{ precio(a.precio) }}</td>
        </tr>
      </tbody>
    </table>

    <table v-if="pestana === 'clientes' && clientes.length" class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Nombre</th>
          <th>NIF</th>
          <th>Población</th>
          <th>Teléfono</th>
          <th class="num">Tarifa</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="c in clientes" :key="c.codigo">
          <td>{{ c.codigo }}</td>
          <td>
            {{ c.razonSocial }}
            <span v-if="c.razonSocial2" class="sec">{{ c.razonSocial2 }}</span>
          </td>
          <td>{{ c.nif }}</td>
          <td>{{ c.poblacion }}</td>
          <td>{{ c.telefono }}</td>
          <td class="num">{{ c.tarifa }}</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.consulta {
  padding: 16px 20px;
}
.consulta__cab {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}
.pestanas button {
  border: 1px solid #bbb;
  background: #f5f5f5;
  padding: 6px 14px;
  cursor: pointer;
}
.pestanas button:first-child {
  border-radius: 4px 0 0 4px;
}
.pestanas button:last-child {
  border-radius: 0 4px 4px 0;
  border-left: 0;
}
.pestanas button.activa {
  background: #1565c0;
  border-color: #1565c0;
  color: #fff;
}
.consulta__buscar {
  flex: 1;
  min-width: 240px;
  padding: 6px 10px;
  font-size: 1rem;
}
.consulta__origen {
  margin: 8px 0;
  color: #555;
  font-size: 0.9rem;
}
.consulta__origen--local {
  color: #c62828;
  font-weight: 600;
}
.consulta__error {
  color: #c62828;
}
.consulta__nota {
  color: #777;
}
.tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
}
.tabla th,
.tabla td {
  border-bottom: 1px solid #e0e0e0;
  padding: 4px 8px;
  text-align: left;
}
.tabla .num {
  text-align: right;
}
.bloqueado {
  color: #999;
}
.sec {
  display: block;
  color: #777;
  font-size: 0.85rem;
}
</style>
