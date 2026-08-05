<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePuestoContextoResuelto } from '@/composables/usePuestoContextoResuelto'
import { usePermisos } from '@/composables/usePermisos'
import PuestoEquipoModal from '@/components/puestos/PuestoEquipoModal.vue'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

const MODULO = 'puestos-parametros'

const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()
const modalAbierto = ref(false)

const puedeVer = computed(() => puede(MODULO, 'ver'))
const equipoLocal = computed(() => ({
  empresaCodigo: puestoContexto.empresaCodigo,
  puestoCodigo: puestoContexto.puestoCodigo,
}))
const { contexto, loading, error, recargar } = usePuestoContextoResuelto(equipoLocal)

function abrirReconfigurar() {
  modalAbierto.value = true
}

function onConfirmado() {
  recargar()
}
</script>

<template>
  <section class="parametros-puesto-view">
    <h2>Parametros del puesto</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver puestos de trabajo.</p>

    <template v-else>
      <p v-if="!puestoContexto.configurado" class="aviso">
        Este equipo no tiene identificador, empresa y puesto configurados. Configurelos para continuar.
      </p>

      <div v-if="puestoContexto.configurado" class="panel">
        <p v-if="loading" class="hint">Cargando...</p>
        <p v-else-if="error" class="error">{{ error }}</p>

        <template v-else-if="contexto">
          <dl class="contexto-grid">
            <div class="fila">
              <dt>Equipo</dt>
              <dd>
                <span class="codigo">{{ puestoContexto.equipoId }}</span>
              </dd>
            </div>
            <div class="fila">
              <dt>Empresa</dt>
              <dd>
                <span class="codigo">{{ contexto.empresa.codigo }}</span>
                {{ contexto.empresa.nombre }}
              </dd>
            </div>
            <div class="fila">
              <dt>Puesto</dt>
              <dd>
                <span class="codigo">{{ contexto.puesto.codigo }}</span>
                {{ contexto.puesto.descripcion }}
              </dd>
            </div>
            <div class="fila">
              <dt>Almacen</dt>
              <dd v-if="contexto.almacen">
                <span class="codigo">{{ contexto.almacen.codigo }}</span>
                {{ contexto.almacen.descripcion }}
              </dd>
              <dd v-else class="sin-dato">Sin almacen en la tienda</dd>
            </div>
          </dl>
        </template>
      </div>

      <p class="hint">
        <template v-if="puestoContexto.enElectron">
          Configuracion guardada en el disco de este PC. Limpiar la cache del navegador no la borra.
        </template>
        <template v-else>
          La configuracion se guarda en el servidor por identificador de equipo. Si limpia la cache, vuelva a
          indicar el mismo identificador para recuperarla.
        </template>
      </p>

      <button type="button" class="btn-reconfig" @click="abrirReconfigurar">
        {{ puestoContexto.configurado ? 'Reconfigurar este equipo' : 'Configurar este equipo' }}
      </button>

      <PuestoEquipoModal
        :open="modalAbierto"
        :obligatorio="!puestoContexto.configurado"
        @cerrar="modalAbierto = false"
        @confirmado="onConfirmado"
      />
    </template>
  </section>
</template>

<style scoped>
.parametros-puesto-view h2 {
  margin: 0 0 0.75rem;
}

.panel {
  padding: 1rem;
  background: #f8fafc;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  margin-bottom: 0.75rem;
}

.contexto-grid {
  margin: 0;
  display: grid;
  gap: 0.75rem;
}

.fila {
  display: grid;
  grid-template-columns: 6rem 1fr;
  gap: 0.5rem;
  align-items: baseline;
}

dt {
  font-weight: 600;
  color: #475569;
}

dd {
  margin: 0;
}

.codigo {
  display: inline-block;
  min-width: 2.5rem;
  margin-right: 0.5rem;
  padding: 0.1rem 0.35rem;
  background: #e0f2fe;
  border-radius: 4px;
  font-weight: 600;
  text-align: center;
}

.sin-dato {
  color: #94a3b8;
  font-style: italic;
}

.aviso {
  padding: 0.65rem 0.85rem;
  background: #fef3c7;
  border: 1px solid #fcd34d;
  border-radius: 8px;
  color: #92400e;
  margin-bottom: 0.75rem;
}

.hint {
  font-size: 0.8rem;
  color: #64748b;
  margin: 0.5rem 0;
}

.error {
  color: #b91c1c;
}

.btn-reconfig {
  margin-top: 0.5rem;
  padding: 0.45rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  cursor: pointer;
  font-size: 0.85rem;
}
</style>
