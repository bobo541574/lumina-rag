<template>
  <input
    :type="type"
    :value="modelValue"
    @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    @change="$emit('change', $event)"
    v-bind="$attrs"
    class="w-full border border-surface-300 rounded-lg px-3 py-2 text-sm text-surface-900 placeholder-surface-400 bg-white transition-colors focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 focus:outline-none disabled:bg-surface-100 disabled:text-surface-500"
  />
</template>

<script setup lang="ts">
defineProps<{
  modelValue?: string | number | null
  type?: string
}>()

defineEmits<{
  'update:modelValue': [value: string]
  change: [e: Event]
}>()
</script>
