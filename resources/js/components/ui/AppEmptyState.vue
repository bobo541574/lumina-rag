<template>
  <div class="flex flex-col items-center justify-center text-center px-6 py-12">
    <div v-if="$slots.icon || icon" class="text-surface-400 mb-3">
      <slot name="icon">
        <svg v-if="icon === 'inbox'" class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0l-3 4h-4l-2 2H9l-2-2H3m17 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5" />
        </svg>
        <svg v-else-if="icon === 'chat'" class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.3-3.9C3.5 15 3 13.5 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
        <svg v-else-if="icon === 'document'" class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <svg v-else class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </slot>
    </div>
    <p v-if="title" class="text-sm font-medium text-surface-900">{{ title }}</p>
    <p v-if="description" class="text-sm text-surface-500 mt-1 max-w-sm">{{ description }}</p>
    <div v-if="$slots.default" class="text-sm text-surface-500 mt-1">
      <slot />
    </div>
    <div v-if="$slots.action" class="mt-4">
      <slot name="action" />
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  icon?: 'inbox' | 'chat' | 'document' | 'info'
  title?: string
  description?: string
}>()
</script>
