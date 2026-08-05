import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'

export type PermisosMap = Record<string, Record<string, boolean>>

export interface Usuario {
  codigo: string
  nombre: string
  rolCodigo: string | null
}

export const useAuthStore = defineStore('auth', () => {
  const usuario = ref<Usuario | null>(null)
  const permisos = ref<PermisosMap>({})
  const cargado = ref(false)

  async function login(codigo: string, password: string) {
    const { data } = await api.post('/api/auth/login', { usuario: codigo, password })
    usuario.value = data.usuario
    permisos.value = data.permisos ?? {}
    cargado.value = true
  }

  async function logout() {
    await api.post('/api/auth/logout')
    usuario.value = null
    permisos.value = {}
    cargado.value = false
  }

  async function fetchMe() {
    const { data } = await api.get('/api/auth/me')
    usuario.value = data.usuario
    permisos.value = data.permisos ?? {}
    cargado.value = true
  }

  return { usuario, permisos, cargado, login, logout, fetchMe }
})
