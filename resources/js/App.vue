<template>
  <div v-if="auth.isAuthenticated" class="min-h-screen bg-gray-50">
    <header class="bg-white border-b border-gray-200 px-6 py-4">
      <div class="max-w-7xl mx-auto flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900">Lumina RAG</h1>
        <nav class="flex items-center gap-6">
          <router-link to="/" class="text-sm text-gray-600 hover:text-gray-900">Chat</router-link>
          <router-link to="/documents" class="text-sm text-gray-600 hover:text-gray-900">Documents</router-link>
          <router-link to="/settings" class="text-sm text-gray-600 hover:text-gray-900">Settings</router-link>
          <span class="text-sm text-gray-400 mx-2">|</span>
          <span class="text-sm text-gray-500">{{ auth.user?.name }}</span>
          <button @click="handleLogout" class="text-sm text-red-600 hover:text-red-700">Sign out</button>
        </nav>
      </div>
    </header>
    <main class="max-w-7xl mx-auto px-6 py-8">
      <router-view />
    </main>
  </div>

  <router-view v-else />
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/authStore'

const auth = useAuthStore()
const router = useRouter()

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}
</script>
