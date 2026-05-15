<template>
  <AppModal
    :model-value="modelValue"
    :title="title"
    size="sm"
    :dismissable="!loading"
    @update:model-value="handleVisibility"
  >
    <p class="px-6 py-4 text-sm text-surface-700 whitespace-pre-line">{{ message }}</p>
    <template #footer>
      <div class="flex justify-end gap-2">
        <AppButton
          variant="ghost"
          :disabled="loading"
          @click="cancel"
        >
          {{ cancelLabel }}
        </AppButton>
        <AppButton
          :variant="confirmVariant"
          :loading="loading"
          :loading-label="confirmLoadingLabel"
          @click="confirm"
        >
          {{ confirmLabel }}
        </AppButton>
      </div>
    </template>
  </AppModal>
</template>

<script setup lang="ts">
import AppModal from './AppModal.vue'
import AppButton from './AppButton.vue'

const props = withDefaults(defineProps<{
  modelValue: boolean
  title: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  confirmLoadingLabel?: string
  confirmVariant?: 'primary' | 'danger' | 'secondary'
  loading?: boolean
}>(), {
  confirmLabel: 'Confirm',
  cancelLabel: 'Cancel',
  confirmLoadingLabel: 'Working…',
  confirmVariant: 'primary',
  loading: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  confirm: []
  cancel: []
}>()

function handleVisibility(open: boolean) {
  if (props.loading) return
  emit('update:modelValue', open)
  if (!open) emit('cancel')
}

function confirm() {
  emit('confirm')
}

function cancel() {
  emit('update:modelValue', false)
  emit('cancel')
}
</script>
