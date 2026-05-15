<template>
  <label class="inline-flex items-center gap-2 cursor-pointer select-none">
    <input
      type="checkbox"
      :checked="modelValue"
      :aria-checked="indeterminate ? 'mixed' : modelValue"
      @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      v-bind="$attrs"
      class="sr-only"
    />
    <span
      :class="[
        'w-4 h-4 rounded border-2 flex items-center justify-center transition-all',
        (modelValue || indeterminate)
          ? 'bg-brand-600 border-brand-600'
          : 'bg-white border-surface-300 hover:border-surface-400',
      ]"
    >
      <svg v-if="indeterminate" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 12h14" />
      </svg>
      <svg v-else-if="modelValue" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
      </svg>
    </span>
    <span v-if="$slots.default" class="text-sm text-surface-700"><slot /></span>
  </label>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  modelValue?: boolean
  indeterminate?: boolean
}>(), {
  modelValue: false,
  indeterminate: false,
})

defineEmits<{
  'update:modelValue': [value: boolean]
}>()
</script>
