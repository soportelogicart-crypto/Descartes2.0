import axios from 'axios'
import { reportClientError } from './clientLogger'

const baseURL = import.meta.env.VITE_API_BASE_URL || ''

export const api = axios.create({
  baseURL,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
  },
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const url = String(error.config?.url ?? '')
    const status = error.response?.status as number | undefined

    if (status === 401 && !url.includes('/auth/login')) {
      window.location.href = `${import.meta.env.BASE_URL}login`.replace(/\/{2,}/g, '/')
      return Promise.reject(error)
    }

    // No reportar el propio endpoint de logs (evitar bucle).
    if (!url.includes('/api/logs') && status !== undefined && status >= 400) {
      const apiError = error.response?.data?.error
      const codigo = error.response?.data?.codigo
      reportClientError(
        typeof apiError === 'string' ? apiError : error.message || `HTTP ${status}`,
        {
          level: status >= 500 ? 'error' : 'warning',
          action: 'axios.error',
          context: {
            status,
            codigo,
            method: error.config?.method,
            url,
          },
        }
      )
    }

    return Promise.reject(error)
  }
)
