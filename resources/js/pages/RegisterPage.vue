<template>
  <div class="min-h-screen bg-surface-50 flex items-center justify-center px-4">
    <div class="max-w-md w-full bg-white rounded-card shadow-sm border border-surface-200 p-8">
      <h1 class="text-2xl font-semibold text-surface-900 text-center mb-2">Lumina RAG</h1>
      <p class="text-sm text-surface-500 text-center mb-8">Create your account</p>

      <form @submit.prevent="handleRegister" class="space-y-4" novalidate>
        <div>
          <label for="name" class="block text-sm font-medium text-surface-700 mb-1">Name</label>
          <AppInput
            id="name"
            v-model="name"
            type="text"
            required
            autocomplete="name"
            placeholder="Your name"
            :aria-invalid="fieldErrors.name ? 'true' : undefined"
            :aria-describedby="fieldErrors.name ? 'name-error' : undefined"
            @update:modelValue="clearFieldError('name')"
          />
          <p v-if="fieldErrors.name" id="name-error" class="mt-1 text-xs text-danger-600">
            {{ fieldErrors.name }}
          </p>
        </div>

        <div>
          <label for="email" class="block text-sm font-medium text-surface-700 mb-1">Email</label>
          <AppInput
            id="email"
            v-model="email"
            type="email"
            required
            autocomplete="email"
            placeholder="you@example.com"
            :aria-invalid="fieldErrors.email ? 'true' : undefined"
            :aria-describedby="fieldErrors.email ? 'email-error' : undefined"
            @update:modelValue="clearFieldError('email')"
          />
          <p v-if="fieldErrors.email" id="email-error" class="mt-1 text-xs text-danger-600">
            {{ fieldErrors.email }}
          </p>
        </div>

        <div>
          <label for="password" class="block text-sm font-medium text-surface-700 mb-1">Password</label>
          <AppInput
            id="password"
            v-model="password"
            type="password"
            required
            autocomplete="new-password"
            minlength="8"
            placeholder="At least 8 characters"
            :aria-invalid="fieldErrors.password ? 'true' : undefined"
            :aria-describedby="fieldErrors.password ? 'password-error' : undefined"
            @update:modelValue="clearFieldError('password')"
          />
          <p v-if="fieldErrors.password" id="password-error" class="mt-1 text-xs text-danger-600">
            {{ fieldErrors.password }}
          </p>
        </div>

        <div v-if="globalError" class="bg-danger-50 border border-danger-200 rounded-card px-4 py-3 text-sm text-danger-700">
          {{ globalError }}
        </div>

        <AppButton
          type="submit"
          variant="primary"
          block
          :loading="isLoading"
          loading-label="Creating account…"
        >
          Create account
        </AppButton>
      </form>

      <p class="text-sm text-surface-500 text-center mt-6">
        Already have an account?
        <router-link to="/login" class="text-brand-600 hover:text-brand-700 font-medium">Sign in</router-link>
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

const name = ref('')
const email = ref('')
const password = ref('')
const isLoading = ref(false)
const globalError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

function clearFieldError(field: string) {
  if (fieldErrors.value[field]) {
    delete fieldErrors.value[field]
  }
}

function applyServerErrors(errors: Record<string, string[] | string>) {
  const next: Record<string, string> = {}
  for (const [field, messages] of Object.entries(errors)) {
    next[field] = Array.isArray(messages) ? messages[0] : messages
  }
  fieldErrors.value = next
}

async function handleRegister() {
  if (!name.value.trim() || !email.value.trim() || !password.value) return

  isLoading.value = true
  globalError.value = null
  fieldErrors.value = {}

  try {
    await auth.register(name.value, email.value, password.value)
    router.push('/')
  } catch (e: any) {
    const data = e?.response?.data
    if (data?.errors && typeof data.errors === 'object') {
      applyServerErrors(data.errors)
      if (data.message && Object.keys(data.errors).length === 0) {
        globalError.value = data.message
      }
    } else {
      globalError.value = data?.message ?? 'Registration failed'
    }
  } finally {
    isLoading.value = false
  }
}
</script>
