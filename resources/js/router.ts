import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/authStore'
import ChatPage from './pages/ChatPage.vue'
import DocumentsPage from './pages/DocumentsPage.vue'
import LoginPage from './pages/LoginPage.vue'
import RegisterPage from './pages/RegisterPage.vue'
import AiModelsPage from './pages/AiModelsPage.vue'
import AiModelManager from './pages/AiModelManager.vue'

const routes = [
  { path: '/login', name: 'login', component: LoginPage, meta: { guest: true } },
  { path: '/register', name: 'register', component: RegisterPage, meta: { guest: true } },
  { path: '/', name: 'chat', component: ChatPage, meta: { auth: true } },
  { path: '/documents', name: 'documents', component: DocumentsPage, meta: { auth: true } },
  { path: '/settings/ai-models', name: 'ai-models', component: AiModelsPage, meta: { auth: true } },
  { path: '/settings/ai-models/new', name: 'ai-model-create', component: AiModelManager, meta: { auth: true } },
  { path: '/settings/ai-models/:id/edit', name: 'ai-model-edit', component: AiModelManager, meta: { auth: true }, props: true },
  { path: '/:pathMatch(.*)*', redirect: '/' },
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
