<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'

const router = useRouter()
const { user, logout } = useAuth()

function openListados() {
  router.push({ name: 'abc-ventas' })
}

function openListadosClientes() {
  router.push({ name: 'abc-clientes' })
}

async function onLogout() {
  await logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="home page-enter">
    <header class="top">
      <div class="brand">
        <span class="brand-abc">ABC</span>
        <span class="brand-name">Ventas</span>
      </div>
      <div class="top-right" v-if="user">
        <div class="user">
          <span class="user-label">Sesión</span>
          <strong>{{ user.nombre || user.usuario }}</strong>
        </div>
        <button type="button" class="btn ghost" @click="onLogout">Cerrar sesión</button>
      </div>
    </header>

    <main class="body">
      <section class="modules">
        <header class="section-head">
          <h1>Módulos</h1>
          <p>Seleccione un listado para continuar.</p>
        </header>

        <div class="module-grid">
          <button type="button" class="module-tile" @click="openListados">
            <span class="tile-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="26" height="26">
                <path
                  d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5z"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                />
                <path d="M8 15V10.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <path d="M12 15V8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <path d="M16 15v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
              </svg>
            </span>
            <span class="tile-kicker">Informes</span>
            <span class="tile-title">Listados Ventas</span>
            <span class="tile-hint">ABC por vendedor</span>
          </button>

          <button type="button" class="module-tile" @click="openListadosClientes">
            <span class="tile-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="26" height="26">
                <path
                  d="M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4z"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                />
                <path
                  d="M8 21v-1a5 5 0 0 1 5-5h6a5 5 0 0 1 5 5v1"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                />
                <path
                  d="M6 11a3.2 3.2 0 1 0-3.2-3.2A3.2 3.2 0 0 0 6 11z"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                />
                <path
                  d="M1.5 21v-.8A4 4 0 0 1 5.5 16.2"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                />
              </svg>
            </span>
            <span class="tile-kicker">Informes</span>
            <span class="tile-title">Listados Clientes</span>
            <span class="tile-hint">ABC por cliente</span>
          </button>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
.home {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.75rem;
  border-bottom: 1px solid rgba(15, 36, 48, 0.1);
  background: rgba(250, 248, 244, 0.72);
  backdrop-filter: blur(8px);
}

.brand {
  display: flex;
  align-items: baseline;
  gap: 0.45rem;
}

.brand-abc {
  font-family: var(--font-display);
  font-size: 1.55rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  color: var(--ink);
}

.brand-name {
  font-size: 1rem;
  font-weight: 500;
  color: var(--muted);
  letter-spacing: 0.02em;
}

.top-right {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.user {
  display: grid;
  justify-items: end;
  line-height: 1.2;
}

.user-label {
  font-size: 0.68rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
}

.user strong {
  font-size: 0.92rem;
  font-weight: 600;
}

.body {
  flex: 1;
  padding: 2rem 1.75rem 2.5rem;
}

.modules {
  max-width: 56rem;
}

.section-head {
  margin-bottom: 1.35rem;
}

.section-head h1 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.75rem;
  letter-spacing: -0.02em;
}

.section-head p {
  margin: 0.35rem 0 0;
  color: var(--muted);
  font-size: 0.95rem;
}

.module-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(11.5rem, 13rem));
  gap: 1rem;
}

.module-tile {
  aspect-ratio: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.25rem;
  padding: 1.1rem;
  border: 1px solid var(--ink);
  background:
    linear-gradient(165deg, rgba(15, 110, 102, 0.08), transparent 45%),
    var(--surface-raised);
  text-align: left;
  transition:
    transform 180ms ease,
    box-shadow 180ms ease,
    border-color 180ms ease,
    background 180ms ease;
}

.module-tile:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow);
  border-color: var(--accent);
  background:
    linear-gradient(165deg, rgba(15, 110, 102, 0.14), transparent 50%),
    var(--surface-raised);
}

.tile-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--accent);
}

.module-tile:active {
  transform: translateY(-1px);
}

.tile-kicker {
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--accent);
}

.tile-title {
  font-family: var(--font-display);
  font-size: 1.2rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  line-height: 1.15;
  color: var(--ink);
}

.tile-hint {
  font-size: 0.8rem;
  color: var(--muted);
}

@media (max-width: 640px) {
  .top,
  .body {
    padding-inline: 1rem;
  }

  .top {
    flex-wrap: wrap;
  }
}
</style>
