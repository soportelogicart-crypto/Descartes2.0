<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { extractApiError } from '@/composables/extractApiError'
import { getAbcBridge } from '@/api/httpBridge'
import { useAuth } from '@/composables/useAuth'
import type { AppConfig } from '@/types/electron'

const router = useRouter()
const { isAuthenticated } = useAuth()

const form = ref({
  server: '',
  database: '',
  user: '',
  password: '',
  port: 1433,
  encrypt: false,
  trustServerCertificate: true,
})

const loading = ref(false)
const saving = ref(false)
const mensaje = ref<string | null>(null)
const error = ref<string | null>(null)

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const api = getAbcBridge()
    if (!api?.getConfig) throw new Error('API no disponible')
    const cfg = await api.getConfig()
    form.value = {
      server: cfg.database?.server || '',
      database: cfg.database?.database || '',
      user: cfg.database?.user || '',
      password: cfg.database?.password || '',
      port: cfg.database?.port || 1433,
      encrypt: cfg.database?.options?.encrypt === true,
      trustServerCertificate: cfg.database?.options?.trustServerCertificate !== false,
    }
  } catch (e) {
    error.value = extractApiError(e, 'No se pudo cargar la configuración')
  } finally {
    loading.value = false
  }
}

async function guardar() {
  saving.value = true
  mensaje.value = null
  error.value = null
  try {
    const api = getAbcBridge()
    if (!api?.saveConfig) throw new Error('API no disponible')
    const payload: Partial<AppConfig> = {
      database: {
        server: form.value.server.trim(),
        database: form.value.database.trim(),
        user: form.value.user.trim(),
        password: form.value.password,
        port: Number(form.value.port) || 1433,
        options: {
          encrypt: form.value.encrypt,
          trustServerCertificate: form.value.trustServerCertificate,
        },
      },
    }
    await api.saveConfig(payload)
    mensaje.value = 'Conexión correcta. Configuración guardada.'
  } catch (e) {
    error.value = extractApiError(e, 'No se pudo conectar o guardar la configuración')
    mensaje.value = null
  } finally {
    saving.value = false
  }
}

function volver() {
  if (isAuthenticated.value) {
    router.push({ name: 'home' })
    return
  }
  router.push({ name: 'login' })
}

onMounted(cargar)
</script>

<template>
  <section class="config-page page-enter">
    <header class="head">
      <button type="button" class="btn ghost" @click="volver">← Volver</button>
      <h1>Configuración</h1>
      <p>Parámetros de conexión a SQL Server.</p>
    </header>

    <form v-if="!loading" class="form" @submit.prevent="guardar">
      <label class="field">
        Servidor
        <input v-model="form.server" required />
      </label>
      <label class="field">
        Base de datos
        <input v-model="form.database" required />
      </label>
      <div class="row-2">
        <label class="field">
          Usuario SQL
          <input v-model="form.user" required autocomplete="username" />
        </label>
        <label class="field">
          Puerto
          <input v-model.number="form.port" type="number" min="1" max="65535" />
        </label>
      </div>
      <label class="field">
        Contraseña SQL
        <input v-model="form.password" type="password" autocomplete="current-password" />
      </label>

      <div class="checks">
        <label class="check">
          <input v-model="form.encrypt" type="checkbox" />
          Encrypt
        </label>
        <label class="check">
          <input v-model="form.trustServerCertificate" type="checkbox" />
          Trust server certificate
        </label>
      </div>

      <p v-if="mensaje" class="msg-ok">{{ mensaje }}</p>
      <p v-if="error" class="msg-error">{{ error }}</p>

      <button class="btn primary" type="submit" :disabled="saving">
        {{ saving ? 'Comprobando conexión…' : 'Guardar' }}
      </button>
    </form>
    <p v-else class="loading">Cargando…</p>
  </section>
</template>

<style scoped>
.config-page {
  width: min(100%, 28rem);
  margin: 0 auto;
  padding: 1.75rem 1.25rem 2.5rem;
}

.head {
  margin-bottom: 1.4rem;
}

.head h1 {
  margin: 0.55rem 0 0.3rem;
  font-family: var(--font-display);
  font-size: 1.7rem;
  letter-spacing: -0.02em;
}

.head p {
  margin: 0;
  color: var(--muted);
}

.form {
  display: grid;
  gap: 0.85rem;
}

.row-2 {
  display: grid;
  grid-template-columns: 1.4fr 0.8fr;
  gap: 0.75rem;
}

.checks {
  display: grid;
  gap: 0.45rem;
  margin-top: 0.15rem;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: var(--ink-soft);
}

.loading {
  color: var(--muted);
}

@media (max-width: 520px) {
  .row-2 {
    grid-template-columns: 1fr;
  }
}
</style>
