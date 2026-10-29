import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import { olvidarInstalacionEstado } from '@/api/instalacion'
import { puenteOffline } from '@/offline/estadoConexion'

export type PermisosMap = Record<string, Record<string, boolean>>

export interface Usuario {
  codigo: string
  nombre: string
  rolCodigo: string | null
  rolNombre?: string | null
}

export const useAuthStore = defineStore('auth', () => {
  const usuario = ref<Usuario | null>(null)
  const permisos = ref<PermisosMap>({})
  const cargado = ref(false)
  /** Sesión abierta contra la base local (sin conexión): solo TPV. */
  const sesionLocal = ref(false)

  async function login(codigo: string, password: string) {
    const { data } = await api.post('/api/auth/login', { usuario: codigo, password })
    usuario.value = data.usuario
    permisos.value = data.permisos ?? {}
    sesionLocal.value = data.offline === true
    cargado.value = true
    if (!data.offline) {
      void puenteOffline()
        ?.recordarUsuario({ codigo, password, usuario: data.usuario, permisos: data.permisos ?? {} })
        .catch(() => {})
    }
  }

  async function logout() {
    olvidarInstalacionEstado()
    void puenteOffline()
      ?.peticion({ method: 'post', url: '/api/auth/logout' })
      .catch(() => {})
    await api.post('/api/auth/logout')
    usuario.value = null
    permisos.value = {}
    sesionLocal.value = false
    cargado.value = false
  }

  async function fetchMe() {
    const { data } = await api.get('/api/auth/me')
    usuario.value = data.usuario
    permisos.value = data.permisos ?? {}
    sesionLocal.value = data.offline === true
    cargado.value = true
  }

  return { usuario, permisos, cargado, sesionLocal, login, logout, fetchMe }
})
