import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { installGlobalErrorReporting } from './api/clientLogger'
import { cargarVinculoInstalacion } from './api/vinculoInstalacion'
import { registerElectronNavigation } from './bridge/electronNavigation'
import './style.css'
import './assets/mantenimiento-listado.css'
import './views/listados/abc-listado-hub.css'

installGlobalErrorReporting()

const app = createApp(App)
app.use(createPinia())

// app.use(router) lanza ya la primera navegación y su guard pide el estado:
// sin el vínculo cargado iría a la instalación por defecto.
void cargarVinculoInstalacion().finally(() => {
  app.use(router)
  registerElectronNavigation(router)
  app.mount('#app')
})
