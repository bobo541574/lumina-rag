<template>
  <div class="min-h-screen bg-surface-50 flex items-center justify-center px-4">
    <div class="max-w-md w-full bg-white rounded-card shadow-sm border border-surface-200 p-8">
      <h1 class="text-2xl font-semibold text-surface-900 text-center mb-2">Lumina RAG</h1>
      <p class="text-sm text-surface-500 text-center mb-8">Sign in to your account</p>

      <form @submit.prevent="handleLogin" class="space-y-4" novalidate>
        <div>
          <label for="email" class="block text-sm font-medium text-surface-700 mb-1">Email</label>
          <AppInput
            id="email"
            v-model="email"
            type="email"
            required
            autocomplete="email"
            placeholder="you@example.com"
          />
        </div>

        <div>
          <label for="password" class="block text-sm font-medium text-surface-700 mb-1">Password</label>
          <AppInput
            id="password"
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="••••••••"
          />
        </div>

        <div v-if="error" class="bg-danger-50 border border-danger-200 rounded-card px-4 py-3 text-sm text-danger-700">
          {{ error }}
        </div>

        <AppButton
          type="submit"
          variant="primary"
          block
          :loading="isLoading"
          loading-label="Signing in…"
        >
          Sign in
        </AppButton>
      </form>

      <p class="text-sm text-surface-500 text-center mt-6">
        Don't have an account?
        <router-link to="/register" class="text-brand-600 hover:text-brand-700 font-medium">Register</router-link>
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import AppInput from '../components/ui/AppInput.vue'
import AppButton from '../components/ui/AppButton.vue'
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
    error.value = e?.response?.data?.message ?? 'Invalid email or password'
  } finally {
    isLoading.value = false
  }
}
</script>
