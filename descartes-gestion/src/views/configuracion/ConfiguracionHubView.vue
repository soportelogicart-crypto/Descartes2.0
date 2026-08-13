<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { DOCUMENTOS_PLANTILLAS_SCOPES } from '@/config/documentos-plantillas'

const opciones = [
  {
    id: DOCUMENTOS_PLANTILLAS_SCOPES.albaranes.id,
    titulo: DOCUMENTOS_PLANTILLAS_SCOPES.albaranes.titulo,
    descripcion: DOCUMENTOS_PLANTILLAS_SCOPES.albaranes.descripcionHub,
    ruta: DOCUMENTOS_PLANTILLAS_SCOPES.albaranes.ruta,
    disponible: true,
  },
  {
    id: DOCUMENTOS_PLANTILLAS_SCOPES.tickets.id,
    titulo: DOCUMENTOS_PLANTILLAS_SCOPES.tickets.titulo,
    descripcion: DOCUMENTOS_PLANTILLAS_SCOPES.tickets.descripcionHub,
    ruta: DOCUMENTOS_PLANTILLAS_SCOPES.tickets.ruta,
    disponible: true,
  },
  {
    id: DOCUMENTOS_PLANTILLAS_SCOPES.etiquetas.id,
    titulo: DOCUMENTOS_PLANTILLAS_SCOPES.etiquetas.titulo,
    descripcion: DOCUMENTOS_PLANTILLAS_SCOPES.etiquetas.descripcionHub,
    ruta: DOCUMENTOS_PLANTILLAS_SCOPES.etiquetas.ruta,
    disponible: true,
  },
  {
    id: 'impresoras',
    titulo: 'Impresoras y periféricos',
    descripcion: 'Próximamente: acceso rápido a la configuración de impresoras del puesto.',
    ruta: '',
    disponible: false,
  },
  {
    id: 'equipo',
    titulo: 'Equipo / puesto',
    descripcion: 'Próximamente: datos del equipo local y asignación de puesto.',
    ruta: '',
    disponible: false,
  },
]
</script>

<template>
  <section class="config-hub">
    <h2>Configuración</h2>
    <p class="intro">Elija una opción para configurar el sistema.</p>

    <div class="opciones">
      <component
        :is="op.disponible ? RouterLink : 'div'"
        v-for="op in opciones"
        :key="op.id"
        :to="op.disponible ? op.ruta : undefined"
        class="opcion"
        :class="{ disabled: !op.disponible }"
      >
        <h3>{{ op.titulo }}</h3>
        <p>{{ op.descripcion }}</p>
        <span v-if="!op.disponible" class="badge">Próximamente</span>
        <span v-else class="badge open">Abrir</span>
      </component>
    </div>
  </section>
</template>

<style scoped>
.config-hub {
  max-width: 52rem;
}

.config-hub h2 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
}

.intro {
  margin: 0 0 1.25rem;
  color: #64748b;
  font-size: 0.9rem;
}

.opciones {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
}

.opcion {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 1rem 1.1rem;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  background: #fff;
  text-decoration: none;
  color: inherit;
  min-height: 7.5rem;
  box-sizing: border-box;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.opcion:not(.disabled):hover {
  border-color: #3b82f6;
  box-shadow: 0 1px 4px rgb(15 23 42 / 8%);
}

.opcion.disabled {
  opacity: 0.65;
  background: #f8fafc;
  cursor: default;
}

.opcion h3 {
  margin: 0;
  font-size: 1rem;
  color: #0f172a;
}

.opcion p {
  margin: 0;
  flex: 1;
  font-size: 0.82rem;
  color: #64748b;
  line-height: 1.4;
}

.badge {
  align-self: flex-start;
  font-size: 0.7rem;
  font-weight: 600;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  background: #e2e8f0;
  color: #475569;
}

.badge.open {
  background: #dbeafe;
  color: #1d4ed8;
}
</style>
