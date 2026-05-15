<template>
  <span
    role="status"
    :aria-label="label"
    :class="[
      'inline-block rounded-full border-current border-t-transparent animate-spin align-[-0.125em]',
      size === 'xs' && 'w-3 h-3 border-2',
      size === 'sm' && 'w-4 h-4 border-2',
      size === 'md' && 'w-5 h-5 border-2',
      size === 'lg' && 'w-8 h-8 border-[3px]',
    ]"
  />
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  size?: 'xs' | 'sm' | 'md' | 'lg'
  label?: string
}>(), {
  size: 'sm',
  label: 'Loading',
})
</script>
