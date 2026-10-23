<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const usuario = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await auth.login(usuario.value, password.value)
    const redirect = (route.query.redirect as string) || '/'
    await router.push(redirect)
  } catch {
    error.value = 'Usuario o contrasena incorrectos'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login">
    <form class="card" @submit.prevent="onSubmit">
      <h1>Descartes Gestion</h1>
      <p>Inicie sesion para acceder al modulo de Mantenimiento.</p>
      <label>
        Usuario
        <input v-model="usuario" required autocomplete="username" />
      </label>
      <label>
        Contrasena
        <input v-model="password" type="password" required autocomplete="current-password" />
      </label>
      <p v-if="error" class="error">{{ error }}</p>
      <button type="submit" :disabled="loading">{{ loading ? 'Entrando...' : 'Entrar' }}</button>
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
  background: #2563eb;
  color: #fff;
  font-weight: 600;
}

.error {
  color: #b91c1c;
  margin: 0;
}
</style>
