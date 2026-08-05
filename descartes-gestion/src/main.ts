import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { installGlobalErrorReporting } from './api/clientLogger'
import './style.css'

installGlobalErrorReporting()

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
