import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { User } from '../types'
import { authService, type AuthResult } from '../services/authService'

const TOKEN_KEY = 'lumina_token'

/**
 * Auth Store
 *
 * Manages authentication state — user profile, token, and session lifecycle.
 * Persists the Bearer token in localStorage and provides login, register,
 * logout, and initialisation (token validation on app boot).
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const isInitialized = ref(false)

  const isAuthenticated = computed(() => !!user.value && !!token.value)

  /**
   * Persist auth result to state and localStorage
   *
   * @param {AuthResult} result Auth response with user and token. Example: { user: { id: "01J...", name: "Alice", email: "alice@example.com" }, token: "abc123..." }
   */
  function setAuth(result: AuthResult) {
    user.value = result.user
    token.value = result.token
    localStorage.setItem(TOKEN_KEY, result.token)
  }

  /**
   * Clear auth state and remove token from localStorage
   */
  function clearAuth() {
    user.value = null
    token.value = null
    localStorage.removeItem(TOKEN_KEY)
  }

  /**
   * Initialise auth on app boot
   *
   * If a token exists in localStorage, validates it by fetching the current
   * user. If the token is invalid/expired, clears auth. Sets isInitialized
   * when done so the router can proceed.
   */
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

  /**
   * Log in with email and password
   *
   * @param {string} email User email. Example: "alice@example.com"
   * @param {string} password User password. Example: "secret123"
   * @returns {Promise<AuthResult>} Auth result with user and token. Example: { user: { id: "01J...", ... }, token: "abc..." }
   * @throws When credentials are invalid
   */
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

  /**
   * Register a new account
   *
   * @param {string} name Display name. Example: "Alice"
   * @param {string} email Email address. Example: "alice@example.com"
   * @param {string} password Password (min 8 chars). Example: "secret123"
   * @returns {Promise<AuthResult>} Auth result with user and token. Example: { user: { id: "01J...", ... }, token: "abc..." }
   * @throws When validation fails or server rejects
   */
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

  /**
   * Log out the current user
   *
   * Calls the server logout endpoint (best-effort), then clears local auth
   * state regardless of the server response.
   */
  async function logout() {
    try {
      await authService.logout()
    } catch {
      // ignore network errors on logout
    } finally {
      clearAuth()
    }
  }

  /**
   * Clear the current error state
   */
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
