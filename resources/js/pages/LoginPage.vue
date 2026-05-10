<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
    <div class="max-w-md w-full bg-white rounded-lg shadow-sm border border-gray-200 p-8">
      <h1 class="text-2xl font-semibold text-gray-900 text-center mb-2">Lumina RAG</h1>
      <p class="text-sm text-gray-500 text-center mb-8">Sign in to your account</p>

      <form @submit.prevent="handleLogin" class="space-y-4">
        <div>
          <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            required
            autocomplete="email"
            class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="you@example.com"
          />
        </div>

        <div>
          <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="••••••••"
          />
        </div>

        <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
          {{ error }}
        </div>

        <button
          type="submit"
          :disabled="isLoading"
          class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          <span v-if="isLoading" class="inline-flex items-center gap-2">
            <span class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
            Signing in...
          </span>
          <span v-else>Sign in</span>
        </button>
      </form>

      <p class="text-sm text-gray-500 text-center mt-6">
        Don't have an account?
        <router-link to="/register" class="text-blue-600 hover:text-blue-700 font-medium">Register</router-link>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const isLoading = ref(false)
const error = ref<string | null>(null)

async function handleLogin() {
  if (!email.value.trim() || !password.value) return

  isLoading.value = true
  error.value = null

  try {
    await auth.login(email.value, password.value)
    router.push('/')
  } catch (e: any) {
    error.value = e.response?.data?.message ?? 'Invalid email or password'
  } finally {
    isLoading.value = false
  }
}
</script>
