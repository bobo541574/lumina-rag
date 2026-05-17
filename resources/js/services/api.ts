/**
 * HTTP API client
 *
 * Axios-based client pre-configured for the Laravel backend. Automatically
 * attaches the Bearer token from localStorage and handles 401 responses by
 * clearing auth and redirecting to login.
 */
import axios, { type AxiosInstance, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import type { ApiResponse } from '../types'

const TOKEN_KEY = 'lumina_token'

const api: AxiosInstance = axios.create({
  baseURL: '/api',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response: AxiosResponse) => response,
  (error) => {
    if (!error.response) {
      return Promise.reject(new Error('Network error. Please check your connection.'))
    }

    if (error.response.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      window.location.href = '/login'
    }

    return Promise.reject(error)
  },
)

/**
 * GET request
 *
 * Sends an authenticated GET request with optional query parameters.
 *
 * @param {string} url API endpoint path (relative to /api). Example: "/documents"
 * @param {Record<string, unknown>} [params] Query parameters. Example: { page: 1, per_page: 25 }
 * @returns {Promise<ApiResponse<T>>} Decoded API response. Example: { success: true, data: [...], meta: {...} }
 */
export async function get<T>(url: string, params?: Record<string, unknown>): Promise<ApiResponse<T>> {
  const response = await api.get<ApiResponse<T>>(url, { params })
  return response.data
}

/**
 * POST request
 *
 * Sends an authenticated POST request with a JSON body.
 *
 * @param {string} url API endpoint path. Example: "/chat"
 * @param {unknown} [data] Request body payload. Example: { question: "What is..." }
 * @returns {Promise<ApiResponse<T>>} Decoded API response. Example: { success: true, data: { session_id: "01J...", message: {...} } }
 */
export async function post<T>(url: string, data?: unknown): Promise<ApiResponse<T>> {
  const response = await api.post<ApiResponse<T>>(url, data)
  return response.data
}

/**
 * PUT request
 *
 * Sends an authenticated PUT request with a JSON body for full/partial updates.
 *
 * @param {string} url API endpoint path. Example: "/documents/01J..."
 * @param {unknown} [data] Update payload. Example: { title: "New Title" }
 * @returns {Promise<ApiResponse<T>>} Decoded API response. Example: { success: true, data: { id: "01J...", ... } }
 */
export async function put<T>(url: string, data?: unknown): Promise<ApiResponse<T>> {
  const response = await api.put<ApiResponse<T>>(url, data)
  return response.data
}

/**
 * DELETE request
 *
 * Sends an authenticated DELETE request to remove a resource.
 *
 * @param {string} url API endpoint path. Example: "/documents/01J..."
 * @returns {Promise<ApiResponse<T>>} Decoded API response. Example: { success: true, message: "Deleted" }
 */
export async function del<T>(url: string): Promise<ApiResponse<T>> {
  const response = await api.delete<ApiResponse<T>>(url)
  return response.data
}

/**
 * Multipart upload
 *
 * Sends a POST request with multipart/form-data encoding for file uploads.
 *
 * @param {string} url API endpoint path. Example: "/documents"
 * @param {FormData} formData Form payload containing the file and metadata. Example: new FormData()
 * @returns {Promise<ApiResponse<T>>} Decoded API response. Example: { success: true, data: { id: "01J...", ... } }
 */
export async function upload<T>(url: string, formData: FormData): Promise<ApiResponse<T>> {
  const response = await api.post<ApiResponse<T>>(url, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return response.data
}

export default api
