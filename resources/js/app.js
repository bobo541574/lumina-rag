import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/authStore'
import 'trix'
import 'trix/dist/trix.css'
import '../css/trix.css'

async function bootstrap() {
  const app = createApp(App)
  const pinia = createPinia()
  app.use(pinia)

  const auth = useAuthStore()
  await auth.init()

  app.use(router)
  app.mount('#app')
}

bootstrap()
