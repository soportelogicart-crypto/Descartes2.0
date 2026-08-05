import { computed, ref } from 'vue'
import type { SessionUser } from '@/types/electron'
import { getAbcBridge } from '@/api/httpBridge'

const user = ref<SessionUser | null>(null)
const ready = ref(false)

export function useAuth() {
  const isAuthenticated = computed(() => !!user.value)

  async function refresh() {
    try {
      user.value = (await getAbcBridge().me()) as SessionUser | null
    } catch {
      user.value = null
    } finally {
      ready.value = true
    }
    return user.value
  }

  async function login(usuario: string, password: string) {
    user.value = (await getAbcBridge().login(usuario, password)) as SessionUser
    return user.value
  }

  async function logout() {
    try {
      await getAbcBridge().logout()
    } finally {
      user.value = null
    }
  }

  return {
    user,
    ready,
    isAuthenticated,
    refresh,
    login,
    logout,
  }
}
