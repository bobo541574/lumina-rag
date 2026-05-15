<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
        @click.self="closeIfDismissable"
      >
        <Transition
          enter-active-class="transition duration-150 ease-out"
          enter-from-class="opacity-0 scale-95"
          enter-to-class="opacity-100 scale-100"
          leave-active-class="transition duration-100 ease-in"
          leave-from-class="opacity-100 scale-100"
          leave-to-class="opacity-0 scale-95"
          appear
        >
          <div
            v-if="modelValue"
            ref="dialogRef"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            :class="[
              'bg-white rounded-card shadow-xl w-full max-h-[90vh] flex flex-col overflow-hidden',
              size === 'sm' && 'max-w-sm',
              size === 'md' && 'max-w-md',
              size === 'lg' && 'max-w-2xl',
              size === 'xl' && 'max-w-4xl',
            ]"
            tabindex="-1"
            @keydown.esc.stop="closeIfDismissable"
          >
            <header v-if="$slots.header || title" class="flex items-center justify-between px-6 py-4 border-b border-surface-200">
              <h2 :id="titleId" class="text-lg font-semibold text-surface-900">
                <slot name="header">{{ title }}</slot>
              </h2>
              <button
                v-if="dismissable"
                type="button"
                class="p-1 -mr-1 rounded text-surface-400 hover:text-surface-700 hover:bg-surface-100 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer transition-colors"
                aria-label="Close dialog"
                @click="emit('update:modelValue', false)"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </header>

            <div class="flex-1 overflow-y-auto">
              <slot />
            </div>

            <footer v-if="$slots.footer" class="px-6 py-4 border-t border-surface-200 bg-surface-50">
              <slot name="footer" />
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch, nextTick, useId } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: boolean
  title?: string
  size?: 'sm' | 'md' | 'lg' | 'xl'
  dismissable?: boolean
}>(), {
  size: 'lg',
  dismissable: true,
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const dialogRef = ref<HTMLElement | null>(null)
const titleId = useId()

watch(() => props.modelValue, async (open) => {
  if (open) {
    document.body.style.overflow = 'hidden'
    await nextTick()
    dialogRef.value?.focus()
  } else {
    document.body.style.overflow = ''
  }
})

function closeIfDismissable() {
  if (props.dismissable) emit('update:modelValue', false)
}
</script>
