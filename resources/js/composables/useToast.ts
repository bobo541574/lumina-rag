import { ref, readonly } from 'vue'

export type ToastVariant = 'success' | 'error' | 'info' | 'warning'

export interface Toast {
  id: string
  message: string
  variant: ToastVariant
  duration: number
}

const toasts = ref<Toast[]>([])

function genId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function push(variant: ToastVariant, message: string, duration = 4000): string {
  const id = genId()
  toasts.value.push({ id, message, variant, duration })
  if (duration > 0) {
    setTimeout(() => dismiss(id), duration)
  }
  return id
}

function dismiss(id: string): void {
  toasts.value = toasts.value.filter((t) => t.id !== id)
}

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
