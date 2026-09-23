<script setup lang="ts">
import type { TpvFuncionExtra } from '@/types/tpv'

defineProps<{
  open: boolean
  funciones: TpvFuncionExtra[]
}>()

const emit = defineEmits<{
  ejecutar: [string]
  cerrar: []
}>()
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @mousedown.prevent>
      <section class="ventana" role="dialog" aria-modal="true">
        <header class="barra">OTRAS FUNCIONES</header>

        <div class="cuerpo">
          <button
            v-for="f in funciones"
            :key="f.id"
            type="button"
            class="funcion"
            :class="f.tono ?? 'normal'"
            :disabled="f.deshabilitada"
            :title="f.ayuda || ''"
            @click="emit('ejecutar', f.id)"
          >
            <span class="etiqueta">{{ f.etiqueta }}</span>
            <small v-if="f.ayuda">{{ f.ayuda }}</small>
          </button>
        </div>

        <footer class="pie">
          <button type="button" class="cerrar" @click="emit('cerrar')">CERRAR</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 5000;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 55%);
}

.ventana {
  width: min(30rem, 94vw);
  max-height: 92vh;
  overflow: auto;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-size: 0.85rem;
  font-weight: 600;
}

/* Dos columnas: teclas grandes para dedo, sin pasar de dos pantallazos. */
.cuerpo {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 6px;
  padding: 0.75rem;
}

.funcion {
  display: grid;
  gap: 0.15rem;
  min-height: 3.6rem;
  padding: 0.5rem 0.6rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font: inherit;
  text-align: left;
  cursor: pointer;
  touch-action: manipulation;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.funcion:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #94a3b8;
}

.funcion:active:not(:disabled) {
  transform: translateY(1px);
}

.funcion:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.etiqueta {
  font-size: 0.85rem;
  font-weight: 600;
}

.funcion small {
  color: #64748b;
  font-size: 0.7rem;
}

.funcion.aviso {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}

.funcion.peligro {
  background: #fff1f2;
  border-color: #fecdd3;
  color: #be123c;
}

/* Función ya en marcha (p. ej. configurando botones): se ve que está activa. */
.funcion.activo {
  background: #4338ca;
  border-color: #4338ca;
  color: #fff;
}

.funcion.activo small,
.funcion.aviso small,
.funcion.peligro small {
  color: inherit;
  opacity: 0.8;
}

.pie {
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.cerrar {
  width: 100%;
  min-height: 2.9rem;
  background: #e2e8f0;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #334155;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.cerrar:hover {
  background: #cbd5e1;
}
</style>
