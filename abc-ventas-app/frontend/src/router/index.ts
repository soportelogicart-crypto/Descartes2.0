import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import LoginView from '@/views/LoginView.vue'
import HomeView from '@/views/HomeView.vue'
import AbcVentasView from '@/views/AbcVentasView.vue'
import AbcPreviewView from '@/views/AbcPreviewView.vue'
import ConfigView from '@/views/ConfigView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { public: true, titulo: 'Login' },
    },
    {
      path: '/',
      name: 'home',
      component: HomeView,
      meta: { titulo: 'Inicio' },
    },
    {
      path: '/ventas/abc',
      name: 'abc-ventas',
      component: AbcVentasView,
      meta: { titulo: 'Listado ABC Ventas', dimension: 'vendedores' },
    },
    {
      path: '/ventas/abc-clientes',
      name: 'abc-clientes',
      component: AbcVentasView,
      meta: { titulo: 'Listado ABC Clientes', dimension: 'clientes' },
    },
    {
      path: '/ventas/abc/preview',
      name: 'abc-preview',
      component: AbcPreviewView,
      meta: { titulo: 'Previsualización ABC' },
    },
    {
      path: '/configuracion',
      name: 'configuracion',
      component: ConfigView,
      meta: { public: true, titulo: 'Configuración' },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (!auth.ready.value) {
    await auth.refresh()
  }

  if (to.meta.public) {
    if (to.name === 'login' && auth.isAuthenticated.value) {
      return { name: 'home' }
    }
    return true
  }

  if (!auth.isAuthenticated.value) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  return true
})

export default router
