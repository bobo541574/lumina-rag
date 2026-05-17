/**
 * Toast notification composable
 *
 * Global, singleton toast notification system. Provides convenience methods
 * for success, error, info, and warning variants with auto-dismiss.
 * Toasts are rendered by the AppToast component.
 */
import { ref, readonly } from 'vue'

/**
 * Toast variant type
 *
 * Determines the visual style of the toast notification.
 */
export type ToastVariant = 'success' | 'error' | 'info' | 'warning'

/**
 * Toast notification
 *
 * Represents a single toast message displayed in the UI.
 *
 * @property {string} id Unique ID for the toast. Example: "1712345678901-a1b2c3d"
 * @property {string} message Display message. Example: "Document uploaded successfully"
 * @property {ToastVariant} variant Visual variant. Example: "success"
 * @property {number} duration Auto-dismiss duration in ms. Example: 4000
 */
export interface Toast {
  id: string
  message: string
  variant: ToastVariant
  duration: number
}

const toasts = ref<Toast[]>([])

/**
 * Generate a unique toast ID
 *
 * Combines timestamp with a random string for uniqueness.
 *
 * @returns {string} Unique ID. Example: "1712345678901-a1b2c3d"
 */
function genId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

/**
 * Push a new toast notification
 *
 * Adds a toast to the stack and schedules auto-dismiss if duration > 0.
 *
 * @param {ToastVariant} variant Visual variant. Example: "success"
 * @param {string} message Display message. Example: "Saved"
 * @param {number} [duration] Auto-dismiss delay in ms (0 = sticky). Example: 4000
 * @returns {string} The toast ID (can be used with dismiss()). Example: "1712345678901-a1b2c3d"
 */
function push(variant: ToastVariant, message: string, duration = 4000): string {
  const id = genId()
  toasts.value.push({ id, message, variant, duration })
  if (duration > 0) {
    setTimeout(() => dismiss(id), duration)
  }
  return id
}

/**
 * Dismiss a toast by ID
 *
 * @param {string} id Toast ID to remove. Example: "1712345678901-a1b2c3d"
 */
function dismiss(id: string): void {
  toasts.value = toasts.value.filter((t) => t.id !== id)
}

/**
 * Use toast notifications
 *
 * Returns reactive toast array and helper methods for each variant.
 * This is a singleton — the same toast stack is shared across all callers.
 *
 * @returns {Object} Toast API
 * @returns {readonly(Toast[])} toasts - Reactive list of active toasts
 * @returns {function} success - Show a success toast. Example: success("Done!")
 * @returns {function} error - Show an error toast (default 6s). Example: error("Failed")
 * @returns {function} info - Show an info toast. Example: info("Processing...")
 * @returns {function} warning - Show a warning toast. Example: warning("Limit reached")
 * @returns {function} dismiss - Dismiss a toast by ID. Example: dismiss("171...")
 */
export function useToast() {
  return {
    toasts: readonly(toasts),
    success: (message: string, duration?: number) => push('success', message, duration),
    error:   (message: string, duration?: number) => push('error', message, duration ?? 6000),
    info:    (message: string, duration?: number) => push('info', message, duration),
    warning: (message: string, duration?: number) => push('warning', message, duration),
    dismiss,
  }
}
