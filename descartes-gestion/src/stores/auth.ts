import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import { olvidarInstalacionEstado } from '@/api/instalacion'
import { puenteOffline } from '@/offline/estadoConexion'
import {
  aplicarPreferencias,
  normalizarAyudas,
  normalizarIdioma,
  normalizarTema,
  type PreferenciasUi,
} from '@/preferencias/aplicar'

export type PermisosMap = Record<string, Record<string, boolean>>

export interface Usuario {
  codigo: string
  nombre: string
  rolCodigo: string | null
  rolNombre?: string | null
  idioma?: string
  colorTema?: string
  ayudasMenu?: boolean
}

export const useAuthStore = defineStore('auth', () => {
  const usuario = ref<Usuario | null>(null)
  const permisos = ref<PermisosMap>({})
  const cargado = ref(false)
  /** Sesión abierta contra la base local (sin conexión): solo TPV. */
  const sesionLocal = ref(false)

  function tomarSesion(data: { usuario: Usuario; permisos?: PermisosMap; offline?: boolean }) {
    usuario.value = {
      ...data.usuario,
      idioma: normalizarIdioma(data.usuario?.idioma),
      colorTema: normalizarTema(data.usuario?.colorTema),
      ayudasMenu: normalizarAyudas(data.usuario?.ayudasMenu),
    }
    permisos.value = data.permisos ?? {}
    sesionLocal.value = data.offline === true
    cargado.value = true
    aplicarPreferencias(usuario.value)
  }

  async function login(codigo: string, password: string) {
    const { data } = await api.post('/api/auth/login', { usuario: codigo, password })
    tomarSesion(data)
    if (!data.offline) {
      void puenteOffline()
        ?.recordarUsuario({ codigo, password, usuario: data.usuario, permisos: data.permisos ?? {} })
        .catch(() => {})
    }
  }

  /** Solo idioma, color o ayudas. No envía la contraseña ni el resto de la ficha. */
  async function guardarPreferencias(parcial: PreferenciasUi) {
    const { data } = await api.put('/api/auth/preferencias', parcial)
    if (usuario.value) {
      usuario.value = {
        ...usuario.value,
        idioma: normalizarIdioma(data.idioma),
        colorTema: normalizarTema(data.colorTema),
        ayudasMenu: normalizarAyudas(data.ayudasMenu),
      }
      aplicarPreferencias(usuario.value)
    }
  }

  async function logout() {
    olvidarInstalacionEstado()
    aplicarPreferencias({ idioma: 'es', colorTema: 'azul', ayudasMenu: true })
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
    tomarSesion(data)
  }

  return { usuario, permisos, cargado, sesionLocal, login, logout, fetchMe, guardarPreferencias }
})
