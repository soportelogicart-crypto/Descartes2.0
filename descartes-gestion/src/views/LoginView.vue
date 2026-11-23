<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { olvidarInstalacionEstado } from '@/api/instalacion'
import { normalizarEmpresaWeb, recordarEmpresaWeb, vinculoEnMemoria } from '@/api/vinculoInstalacion'
import { isElectronShell } from '@/bridge/electron'
import { extractApiError } from '@/composables/extractApiError'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()

const usuario = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)
const esElectron = isElectronShell()
const empresa = ref(vinculoEnMemoria()?.id ?? '')

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

/** En la web, cambiar de empresa cierra antes la sesión de la anterior. */
async function aplicarEmpresaWeb(): Promise<boolean> {
  const nueva = normalizarEmpresaWeb(empresa.value)
  const actual = vinculoEnMemoria()?.id ?? ''
  // Solo hay sesión que cerrar si este navegador ya estaba en otra empresa.
  if (actual !== '' && nueva !== actual) {
    try {
      await auth.logout()
    } catch {
      auth.usuario = null
      auth.permisos = {}
      auth.cargado = false
    }
  }
  // Quita una clave vieja guardada en el navegador. En la web no hace falta.
  recordarEmpresaWeb(nueva)
  olvidarInstalacionEstado()
  return true
}

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    if (!esElectron) {
      if (!empresa.value.trim()) {
        error.value = 'Escriba el identificador de su empresa'
        return
      }
      await aplicarEmpresaWeb()
    }
    await auth.login(usuario.value, password.value)
    const redirect = (route.query.redirect as string) || '/'
    await router.push(redirect)
  } catch (e: unknown) {
    const codigo = (e as { response?: { data?: { codigo?: string } } }).response?.data?.codigo
    if (e instanceof Error && e.message.startsWith('La empresa solo')) {
      error.value = e.message
    } else if (codigo === 'NO_AUTENTICADO' || codigo === 'CREDENCIALES') {
      error.value = t('login.error')
    } else {
      error.value = extractApiError(e, 'No se pudo entrar')
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login">
    <form class="card" @submit.prevent="onSubmit">
      <h1>{{ t('login.titulo') }}</h1>
      <p>{{ t('login.intro') }}</p>
      <label v-if="!esElectron">
        Empresa
        <input v-model="empresa" autocomplete="organization" placeholder="identificador de la empresa" />
        <small>Lo recibe de su proveedor. Sin empresa no se puede entrar.</small>
      </label>
      <label>
        {{ t('login.usuario') }}
        <input v-model="usuario" required autocomplete="username" />
      </label>
      <label>
        {{ t('login.contrasena') }}
        <input v-model="password" type="password" required autocomplete="current-password" />
      </label>
      <p v-if="error" class="error">{{ error }}</p>
      <button type="submit" :disabled="loading">{{ loading ? t('login.entrando') : t('login.entrar') }}</button>
    </form>
  </div>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 1rem;
}

.card {
  width: min(100%, 380px);
  background: #fff;
  border-radius: 12px;
  padding: 1.5rem;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
  display: grid;
  gap: 0.75rem;
}

label {
  display: grid;
  gap: 0.25rem;
  font-size: 0.9rem;
}

small {
  color: #6b7280;
  font-size: 0.78rem;
}

input {
  padding: 0.5rem 0.65rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
}

button {
  margin-top: 0.5rem;
  padding: 0.6rem 1rem;
  border: none;
  border-radius: 8px;
  background: var(--tema-acento, #2563eb);
  color: #fff;
  font-weight: 600;
}

.error {
  color: #b91c1c;
  margin: 0;
}
</style>
