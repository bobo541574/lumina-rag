import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/authStore'
import ChatPage from './pages/ChatPage.vue'
import DocumentsPage from './pages/DocumentsPage.vue'
import LoginPage from './pages/LoginPage.vue'
import RegisterPage from './pages/RegisterPage.vue'
import SettingsPage from './pages/SettingsPage.vue'

const routes = [
  { path: '/login', name: 'login', component: LoginPage, meta: { guest: true } },
  { path: '/register', name: 'register', component: RegisterPage, meta: { guest: true } },
  { path: '/', name: 'chat', component: ChatPage, meta: { auth: true } },
  { path: '/documents', name: 'documents', component: DocumentsPage, meta: { auth: true } },
  { path: '/settings', name: 'settings', component: SettingsPage, meta: { auth: true } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, from, next) => {
  const auth = useAuthStore()

  if (!auth.isInitialized) {
    next(false)
    return
  }

  if (to.meta.auth && !auth.isAuthenticated) {
    next({ name: 'login' })
  } else if (to.meta.guest && auth.isAuthenticated) {
    next({ name: 'chat' })
  } else {
    next()
  }
})

export default router
