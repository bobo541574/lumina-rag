/**
 * Application router
 *
 * Vue Router configuration with route definitions and auth guard.
 * Routes are tagged with meta.auth (requires authentication) and
 * meta.guest (redirects to home if already authenticated).
 * The beforeEach guard waits for auth initialisation before resolving.
 */
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/authStore'
import ChatPage from './pages/ChatPage.vue'
import DocumentsPage from './pages/DocumentsPage.vue'
import LoginPage from './pages/LoginPage.vue'
import RegisterPage from './pages/RegisterPage.vue'
import AiModelsPage from './pages/AiModelsPage.vue'
import AiModelManager from './pages/AiModelManager.vue'
import TermAliasesPage from './pages/TermAliasesPage.vue'

const routes = [
  { path: '/login', name: 'login', component: LoginPage, meta: { guest: true } },
  { path: '/register', name: 'register', component: RegisterPage, meta: { guest: true } },
  { path: '/', name: 'chat', component: ChatPage, meta: { auth: true } },
  { path: '/documents', name: 'documents', component: DocumentsPage, meta: { auth: true } },
  { path: '/settings/ai-models', name: 'ai-models', component: AiModelsPage, meta: { auth: true } },
  { path: '/settings/ai-models/new', name: 'ai-model-create', component: AiModelManager, meta: { auth: true } },
  { path: '/settings/ai-models/:id/edit', name: 'ai-model-edit', component: AiModelManager, meta: { auth: true }, props: true },
  { path: '/settings/term-aliases', name: 'term-aliases', component: TermAliasesPage, meta: { auth: true } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

/**
 * Auth navigation guard
 *
 * Blocks navigation until the auth store is initialised. Redirects
 * unauthenticated users to login for auth-protected routes, and
 * authenticated users to chat for guest-only routes (login/register).
 */
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
