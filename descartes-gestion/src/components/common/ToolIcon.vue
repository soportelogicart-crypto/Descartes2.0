<script setup lang="ts">
export type ToolIconName =
  | 'nuevo'
  | 'modificar'
  | 'borrar'
  | 'buscar'
  | 'guardar'
  | 'listado'
  | 'contadores'
  | 'escandallo'
  | 'eans'
  | 'etiquetas'
  | 'ficha'
  | 'consulta'
  | 'bloqueo'
  | 'excepciones'
  | 'aviso'
  | 'estadistica'
  | 'consumo'
  | 'contactos'
  | 'intereses'
  | 'direccion'
  | 'salir'

defineProps<{
  name: ToolIconName
}>()
</script>

<template>
  <svg class="tool-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <!-- Nuevo -->
    <g v-if="name === 'nuevo'">
      <path
        fill="currentColor"
        d="M12 2l2.4 7.2H22l-6 4.4 2.3 7.2L12 16.8 5.7 20.8 8 13.6 2 9.2h7.6L12 2z"
      />
    </g>

    <!-- Modificar (lapiz) -->
    <g v-else-if="name === 'modificar'">
      <path
        fill="currentColor"
        d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"
      />
    </g>

    <!-- Borrar -->
    <g v-else-if="name === 'borrar'">
      <path
        fill="currentColor"
        d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"
      />
    </g>

    <!-- Buscar -->
    <g v-else-if="name === 'buscar'">
      <path
        fill="currentColor"
        d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20l-6-6zM9.5 14C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"
      />
    </g>

    <!-- Guardar -->
    <g v-else-if="name === 'guardar'">
      <path
        fill="currentColor"
        d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4zm-5 16a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm3-10H5V5h10v4z"
      />
    </g>

    <!-- Listado / imprimir -->
    <g v-else-if="name === 'listado'">
      <path
        fill="currentColor"
        d="M19 8H5V5h14v3zm-2 2h4v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-8h4v2h10v-2zm-8 4H5v2h4v-2zm6 0h-4v2h4v-2z"
      />
    </g>

    <!-- Contadores -->
    <g v-else-if="name === 'contadores'">
      <path
        fill="currentColor"
        d="M4 4h16v4H4V4zm0 6h7v10H4V10zm9 0h7v4h-7v-4zm0 6h7v4h-7v-4z"
      />
    </g>

    <!-- Escandallo (documento) -->
    <g v-else-if="name === 'escandallo'">
      <path
        fill="currentColor"
        d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zM8 12h8v2H8v-2zm0 4h8v2H8v-2z"
      />
    </g>

    <!-- Eans (codigo barras) -->
    <g v-else-if="name === 'eans'">
      <path
        fill="currentColor"
        d="M3 5h2v14H3V5zm3 0h1v14H6V5zm2 0h3v14H8V5zm4 0h1v14h-1V5zm2 0h2v14h-2V5zm3 0h1v14h-1V5zm2 0h3v14h-3V5z"
      />
    </g>

    <!-- Etiquetas -->
    <g v-else-if="name === 'etiquetas'">
      <path
        fill="currentColor"
        d="M21.4 11.6l-9-9A1.99 1.99 0 0 0 11 2H4a2 2 0 0 0-2 2v7c0 .53.21 1.04.59 1.41l9 9a2 2 0 0 0 2.82 0l7-7a2 2 0 0 0-.01-2.81zM6.5 8A1.5 1.5 0 1 1 8 6.5 1.5 1.5 0 0 1 6.5 8z"
      />
    </g>

    <!-- Ficha -->
    <g v-else-if="name === 'ficha'">
      <path
        fill="currentColor"
        d="M4 4h16v2H4V4zm0 4h16v12H4V8zm3 3v2h10v-2H7zm0 4v2h7v-2H7z"
      />
    </g>

    <!-- Consulta -->
    <g v-else-if="name === 'consulta'">
      <path
        fill="currentColor"
        d="M19 3H5a2 2 0 0 0-2 2v14l4-2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm-2 10H7v-2h10v2zm0-4H7V7h10v2z"
      />
    </g>

    <!-- Bloqueo -->
    <g v-else-if="name === 'bloqueo'">
      <path
        fill="currentColor"
        d="M12 17a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm6-7h-1V7a5 5 0 0 0-10 0v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zM9 7a3 3 0 0 1 6 0v3H9V7z"
      />
    </g>

    <!-- Excepciones (grafico) -->
    <g v-else-if="name === 'excepciones'">
      <path
        fill="currentColor"
        d="M3.5 18.5l6-6 4 4L22 6.5l-1.4-1.4-7.1 8.6-4-4L2 17.1l1.5 1.4z"
      />
    </g>

    <!-- Aviso (triangulo con !) -->
    <g v-else-if="name === 'aviso'">
      <path fill="currentColor" d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z" />
    </g>

    <!-- Estadistica -->
    <g v-else-if="name === 'estadistica'">
      <path fill="currentColor" d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z" />
    </g>

    <!-- Consumo -->
    <g v-else-if="name === 'consumo'">
      <path
        fill="currentColor"
        d="M3.5 18.5l6-6 4 4L22 6.5l-1.4-1.4-7.1 8.6-4-4L2 17.1l1.5 1.4z"
      />
    </g>

    <!-- Contactos -->
    <g v-else-if="name === 'contactos'">
      <path
        fill="currentColor"
        d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4l-8 5L4 8V6l8 5 8-5v2z"
      />
    </g>

    <!-- Intereses -->
    <g v-else-if="name === 'intereses'">
      <path
        fill="currentColor"
        d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zm-9-7l1.4 1.4L14 10.8l-1.4-1.4L10.4 11.6 9 10.2 7.6 11.6z"
      />
    </g>

    <!-- Direccion -->
    <g v-else-if="name === 'direccion'">
      <path
        fill="currentColor"
        d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"
      />
    </g>

    <!-- Salir / apagado -->
    <g v-else-if="name === 'salir'">
      <path
        fill="currentColor"
        d="M13 3h-2v10h2V3zm4.83 2.17l-1.42 1.42A6.92 6.92 0 0 1 19 12a7 7 0 1 1-14 0c0-2.15.97-4.08 2.5-5.37L6.17 5.17A8.96 8.96 0 0 0 3 12a9 9 0 1 0 18 0c0-2.74-1.23-5.2-3.17-6.83z"
      />
    </g>
  </svg>
</template>

<style scoped>
.tool-icon {
  width: 1.05rem;
  height: 1.05rem;
  display: block;
  flex-shrink: 0;
}
</style>
