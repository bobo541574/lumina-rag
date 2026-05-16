import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { User } from '../types'
import { authService, type AuthResult } from '../services/authService'

const TOKEN_KEY = 'lumina_token'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const isInitialized = ref(false)

  const isAuthenticated = computed(() => !!user.value && !!token.value)

  function setAuth(result: AuthResult) {
    user.value = result.user
    token.value = result.token
    localStorage.setItem(TOKEN_KEY, result.token)
  }

  function clearAuth() {
    user.value = null
    token.value = null
    localStorage.removeItem(TOKEN_KEY)
  }

  async function init() {
    if (!token.value) {
      isInitialized.value = true
      return
    }

    isLoading.value = true
    try {
      const response = await authService.me()
      user.value = response.data
    } catch {
      clearAuth()
    } finally {
      isLoading.value = false
      isInitialized.value = true
    }
  }

  async function login(email: string, password: string) {
    isLoading.value = true
    error.value = null

    try {
      const response = await authService.login(email, password)
      setAuth(response.data)
      return response.data
    } catch (e: any) {
      const msg = e.response?.data?.message ?? 'Login failed'
      error.value = msg
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function register(name: string, email: string, password: string) {
    isLoading.value = true
    error.value = null

    try {
      const response = await authService.register(name, email, password)
      setAuth(response.data)
      return response.data
    } catch (e: any) {
      const msg = e.response?.data?.message ?? 'Registration failed'
      error.value = msg
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function logout() {
    try {
      await authService.logout()
    } catch {
      // ignore network errors on logout
    } finally {
      clearAuth()
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    user,
    token,
    isLoading,
    error,
    isInitialized,
    isAuthenticated,
    init,
    login,
    register,
    logout,
    clearError,
  }
})
