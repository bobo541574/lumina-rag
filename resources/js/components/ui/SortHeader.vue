<template>
  <button
    type="button"
    :aria-sort="ariaSort"
    class="w-full text-left px-4 py-3 font-medium text-surface-600 hover:text-surface-900 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-inset transition-colors flex items-center gap-1"
    @click="$emit('sort', sortKey)"
  >
    <span>
      <slot />
    </span>
    <span class="text-surface-400" aria-hidden="true">
      <svg v-if="!isActive" class="w-3 h-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4M8 15l4 4 4-4" />
      </svg>
      <svg v-else-if="currentDir === 'asc'" class="w-3 h-3 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7" />
      </svg>
      <svg v-else class="w-3 h-3 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
      </svg>
    </span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  sortKey: string
  currentKey: string
  currentDir: 'asc' | 'desc'
}>()

defineEmits<{
  sort: [key: string]
}>()

const isActive = computed(() => props.currentKey === props.sortKey)
const ariaSort = computed<'ascending' | 'descending' | 'none'>(() => {
  if (!isActive.value) return 'none'
  return props.currentDir === 'asc' ? 'ascending' : 'descending'
})
</script>
