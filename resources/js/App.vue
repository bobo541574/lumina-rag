<template>
  <div v-if="auth.isAuthenticated" class="h-screen bg-surface-50 flex flex-col">
    <header class="bg-white border-b border-surface-200 px-4 sm:px-6 flex-shrink-0 relative">
      <div class="max-w-7xl mx-auto h-16 flex items-center justify-between gap-4">
        <h1 class="text-xl font-semibold text-surface-900 flex-shrink-0">Lumina RAG</h1>

        <!-- Desktop nav -->
        <nav class="hidden md:flex items-center gap-2" aria-label="Primary">
          <router-link
            v-for="item in navItems"
            :key="item.to"
            :to="item.to"
            class="text-sm px-3 py-1.5 rounded-lg text-surface-600 hover:text-surface-900 hover:bg-surface-100 transition-colors"
            active-class="!text-brand-700 !bg-brand-50 font-medium"
          >
            {{ item.label }}
          </router-link>
          <span class="text-sm text-surface-300 mx-1" aria-hidden="true">|</span>
          <span class="text-sm text-surface-500 max-w-[12rem] truncate">{{ auth.user?.name }}</span>
          <AppButton variant="ghost" size="sm" @click="handleLogout">Sign out</AppButton>
        </nav>

        <!-- Mobile hamburger -->
        <AppButton
          variant="ghost"
          size="sm"
          class="md:hidden"
          :aria-label="mobileOpen ? 'Close menu' : 'Open menu'"
          :aria-expanded="mobileOpen"
          aria-controls="mobile-nav"
          @click="mobileOpen = !mobileOpen"
        >
          <svg v-if="!mobileOpen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </AppButton>
      </div>

      <!-- Mobile dropdown -->
      <Transition
        enter-active-class="transition duration-150 ease-out origin-top"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-100 ease-in origin-top"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <nav
          v-if="mobileOpen"
          id="mobile-nav"
          class="md:hidden absolute inset-x-0 top-full bg-white border-b border-surface-200 shadow-sm px-4 py-3 flex flex-col gap-1 z-40"
          aria-label="Mobile primary"
        >
          <router-link
            v-for="item in navItems"
            :key="item.to"
            :to="item.to"
            class="text-sm px-3 py-2 rounded-lg text-surface-600 hover:text-surface-900 hover:bg-surface-100 transition-colors"
            active-class="!text-brand-700 !bg-brand-50 font-medium"
            @click="mobileOpen = false"
          >
            {{ item.label }}
          </router-link>
          <div class="pt-2 mt-2 border-t border-surface-200 flex items-center justify-between">
            <span class="text-sm text-surface-500 truncate">{{ auth.user?.name }}</span>
            <AppButton variant="ghost" size="sm" @click="handleLogout">Sign out</AppButton>
          </div>
        </nav>
      </Transition>
    </header>

    <main class="flex-1 min-h-0 overflow-y-auto">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6 sm:py-8 min-h-full flex flex-col">
        <router-view v-slot="{ Component, route: r }">
          <Transition name="fade-route" mode="out-in">
            <component :is="Component" :key="r.fullPath" />
          </Transition>
        </router-view>
      </div>
    </main>
  </div>

  <router-view v-else v-slot="{ Component, route: r }">
    <Transition name="fade-route" mode="out-in">
      <component :is="Component" :key="r.fullPath" />
    </Transition>
  </router-view>

  <AppToast />
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from './stores/authStore'
import AppButton from './components/ui/AppButton.vue'
import AppToast from './components/ui/AppToast.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const mobileOpen = ref(false)

const navItems = [
  { to: '/', label: 'Chat' },
  { to: '/documents', label: 'Documents' },
  { to: '/settings/ai-models', label: 'AI Models' },
]

watch(() => route.fullPath, () => {
  mobileOpen.value = false
})

async function handleLogout() {
  mobileOpen.value = false
  await auth.logout()
  router.push('/login')
}
</script>


<style>
.fade-route-enter-active,
.fade-route-leave-active {
  transition: opacity 150ms ease;
}
.fade-route-enter-from,
.fade-route-leave-to {
  opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
  .fade-route-enter-active,
  .fade-route-leave-active {
    transition: none;
  }
}
</style>
