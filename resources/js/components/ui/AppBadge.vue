<template>
  <span
    :class="[
      'inline-flex items-center gap-1 font-medium whitespace-nowrap',
      shape === 'pill' ? 'rounded-full px-2 py-0.5' : 'rounded px-1.5 py-0.5',
      size === 'xs' && 'text-[10px]',
      size === 'sm' && 'text-xs',
      size === 'md' && 'text-sm',
      variantClass,
    ]"
  >
    <slot />
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  variant?: 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info'
  size?: 'xs' | 'sm' | 'md'
  shape?: 'pill' | 'square'
}>(), {
  variant: 'neutral',
  size: 'sm',
  shape: 'pill',
})

const variantClass = computed(() => {
  switch (props.variant) {
    case 'brand':   return 'bg-brand-100 text-brand-700'
    case 'success': return 'bg-success-100 text-success-800'
    case 'warning': return 'bg-warning-100 text-warning-800'
    case 'danger':  return 'bg-danger-100 text-danger-800'
    case 'info':    return 'bg-info-100 text-info-800'
    default:        return 'bg-surface-100 text-surface-700'
  }
})
</script>
