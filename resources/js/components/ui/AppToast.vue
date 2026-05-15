<template>
  <Teleport to="body">
    <div
      class="fixed top-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none"
      aria-live="polite"
      aria-atomic="true"
    >
      <TransitionGroup
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 translate-x-2"
        enter-to-class="opacity-100 translate-x-0"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-for="t in toasts"
          :key="t.id"
          :class="[
            'pointer-events-auto min-w-[260px] max-w-sm flex items-start gap-3 px-4 py-3 rounded-card shadow-lg border',
            t.variant === 'success' && 'bg-success-50 border-success-100 text-success-800',
            t.variant === 'error'   && 'bg-danger-50 border-danger-100 text-danger-800',
            t.variant === 'info'    && 'bg-info-50 border-info-100 text-info-800',
            t.variant === 'warning' && 'bg-warning-50 border-warning-100 text-warning-800',
          ]"
          role="status"
        >
          <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path v-if="t.variant === 'success'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            <path v-else-if="t.variant === 'error'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-sm flex-1">{{ t.message }}</p>
          <button
            type="button"
            class="text-current opacity-60 hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-current rounded cursor-pointer"
            :aria-label="`Dismiss ${t.variant} notification`"
            @click="dismiss(t.id)"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { useToast } from '../../composables/useToast'

const { toasts, dismiss } = useToast()
</script>
