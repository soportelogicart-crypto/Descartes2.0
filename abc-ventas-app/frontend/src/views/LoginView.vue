<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { extractApiError } from '@/composables/extractApiError'

const usuario = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

const auth = useAuth()
const router = useRouter()

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await auth.login(usuario.value, password.value)
    await router.push({ name: 'home' })
  } catch (e) {
    error.value = extractApiError(e, 'Codigo o contraseña incorrectos')
  } finally {
    loading.value = false
  }
}

function openConfiguracion() {
  router.push({ name: 'configuracion' })
}
</script>

<template>
  <div class="login page-enter">
    <aside class="brand-pane" aria-hidden="true">
      <div class="brand-mark">ABC</div>
      <p class="brand-tag">Análisis de ventas por vendedor</p>
    </aside>

    <main class="login-main">
      <form class="login-form" @submit.prevent="onSubmit">
        <header class="login-head">
          <p class="eyebrow">Acceso</p>
          <h1>ABC Ventas</h1>
          <p class="lede">Inicie sesión con su código de vendedor.</p>
        </header>

        <label class="field">
          Código vendedor
          <input v-model="usuario" required autocomplete="username" autofocus />
        </label>
        <label class="field">
          Contraseña
          <input v-model="password" type="password" required autocomplete="current-password" />
        </label>

        <p v-if="error" class="msg-error" role="alert">{{ error }}</p>

        <button class="btn primary submit" type="submit" :disabled="loading">
          {{ loading ? 'Entrando…' : 'Entrar' }}
        </button>

        <button type="button" class="btn ghost config-link" @click="openConfiguracion">
          Configuración de conexión
        </button>
      </form>
    </main>
  </div>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: grid;
  grid-template-columns: minmax(12rem, 0.9fr) minmax(18rem, 1.1fr);
}

.brand-pane {
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 2.5rem 2rem;
  color: #f4f7f6;
  background:
    linear-gradient(160deg, rgba(15, 110, 102, 0.35), transparent 55%),
    linear-gradient(200deg, #0f2430 0%, #163844 55%, #0f6e66 140%);
  position: relative;
  overflow: hidden;
}

.brand-pane::after {
  content: '';
  position: absolute;
  inset: auto -20% -30% 20%;
  height: 70%;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.12), transparent 65%);
  pointer-events: none;
}

.brand-mark {
  position: relative;
  font-family: var(--font-display);
  font-size: clamp(3rem, 8vw, 5rem);
  font-weight: 700;
  letter-spacing: -0.04em;
  line-height: 0.9;
}

.brand-tag {
  position: relative;
  margin: 0;
  max-width: 14rem;
  font-size: 0.95rem;
  line-height: 1.4;
  color: rgba(244, 247, 246, 0.78);
}

.login-main {
  display: grid;
  place-items: center;
  padding: 2rem 1.25rem;
}

.login-form {
  width: min(100%, 22rem);
  display: grid;
  gap: 0.9rem;
}

.login-head {
  margin-bottom: 0.35rem;
}

.eyebrow {
  margin: 0 0 0.35rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--accent);
}

h1 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 2rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  line-height: 1.1;
}

.lede {
  margin: 0.45rem 0 0;
  color: var(--muted);
  font-size: 0.95rem;
  line-height: 1.4;
}

.submit {
  margin-top: 0.35rem;
  width: 100%;
  padding-block: 0.7rem;
}

.config-link {
  width: 100%;
  justify-content: center;
  color: var(--muted);
}

@media (max-width: 720px) {
  .login {
    grid-template-columns: 1fr;
  }

  .brand-pane {
    min-height: 9rem;
    padding: 1.5rem 1.25rem;
    justify-content: center;
  }

  .brand-mark {
    font-size: 2.6rem;
  }
}
</style>
