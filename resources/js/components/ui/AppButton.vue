<template>
  <button
    :disabled="isDisabled"
    :type="type"
    :aria-busy="loading || undefined"
    v-bind="$attrs"
    :class="[
      'inline-flex items-center gap-1.5 rounded-lg font-medium transition-all cursor-pointer focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:opacity-50 disabled:pointer-events-none disabled:cursor-not-allowed',
      align === 'left' ? 'justify-start text-left' : 'justify-center',
      variant === 'primary'      && 'bg-brand-600 text-white hover:bg-brand-700 focus:ring-brand-500',
      variant === 'secondary'    && 'bg-surface-100 text-surface-700 hover:bg-surface-200 focus:ring-surface-400',
      variant === 'danger'       && 'bg-danger-50 text-danger-600 hover:bg-danger-100 focus:ring-danger-600',
      variant === 'ghost'        && 'text-surface-600 hover:text-surface-800 hover:bg-surface-100 focus:ring-surface-400',
      variant === 'danger-ghost' && 'text-surface-400 hover:text-danger-600 hover:bg-danger-50 focus:ring-danger-400',
      size === 'sm' && 'text-xs px-3 py-1.5',
      size === 'md' && 'text-sm px-4 py-2',
      size === 'lg' && 'text-base px-5 py-2.5',
      block && 'w-full',
    ]"
  >
    <AppSpinner v-if="loading" :size="size === 'lg' ? 'md' : 'sm'" :label="loadingLabel" />
    <slot v-if="!loading" />
    <span v-else>{{ loadingLabel }}</span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import AppSpinner from './AppSpinner.vue'

const props = withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost' | 'danger-ghost'
  size?: 'sm' | 'md' | 'lg'
  type?: 'button' | 'submit' | 'reset'
  align?: 'left' | 'center'
  disabled?: boolean
  loading?: boolean
  loadingLabel?: string
  block?: boolean
}>(), {
  variant: 'primary',
  size: 'md',
  type: 'button',
  align: 'center',
  disabled: false,
  loading: false,
  loadingLabel: 'Loading…',
  block: false,
})

const isDisabled = computed(() => props.disabled || props.loading)
</script>
