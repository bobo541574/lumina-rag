<template>
  <AppModal
    :model-value="!!doc"
    size="lg"
    :dismissable="!isSaving"
    @update:model-value="(open) => !open && $emit('close')"
  >
    <template #header>
      <AppInput
        v-model="editTitle"
        class="text-lg font-semibold text-surface-900 bg-transparent border-none focus:ring-0"
        placeholder="Document title"
        aria-label="Document title"
      />
    </template>

    <div v-if="doc">
      <!-- Metadata -->
      <div class="px-6 py-3 bg-surface-50 border-b border-surface-200 text-sm text-surface-600 flex flex-wrap items-center gap-3">
        <span>{{ formatSize(doc.file_size) }} — {{ doc.mime_type }}</span>
        <span class="text-surface-300" aria-hidden="true">•</span>
        <span>{{ doc.chunks_count }} chunks</span>
        <template v-if="doc.embedding_model">
          <span class="text-surface-300" aria-hidden="true">•</span>
          <span>{{ doc.embedding_model }}</span>
        </template>
        <AppBadge :variant="statusVariant(doc.status)" size="sm">{{ doc.status }}</AppBadge>
      </div>

      <!-- Trix Editor -->
      <div class="px-6 py-4">
        <label class="block text-sm font-medium text-surface-700 mb-2">Description</label>
        <div class="trix-wrapper border border-surface-300 rounded-card overflow-hidden focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 transition-colors">
          <trix-editor
            ref="trixRef"
            :value="editDescription"
            @trix-change="onTrixChange"
            class="min-h-[200px]"
          ></trix-editor>
        </div>
        <p class="text-xs text-surface-400 mt-1">Rich text description for document context</p>
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-between gap-2">
        <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
        <div class="flex gap-2 ml-auto">
          <AppButton variant="ghost" size="md" :disabled="isSaving" @click="$emit('close')">Cancel</AppButton>
          <AppButton
            variant="primary"
            size="md"
            :loading="isSaving"
            loading-label="Saving…"
            @click="handleSave"
          >
            Save
          </AppButton>
        </div>
      </div>
    </template>
  </AppModal>
</template>

<script setup lang="ts">
import { ref, watch, nextTick } from 'vue'
import { useDocumentStore } from '../stores/documentStore'
import AppInput from './ui/AppInput.vue'
import AppButton from './ui/AppButton.vue'
import AppBadge from './ui/AppBadge.vue'
import AppModal from './ui/AppModal.vue'
import { useToast } from '../composables/useToast'
import type { Document } from '../types'

const props = defineProps<{
  doc: Document | null
}>()

defineEmits<{
  close: []
}>()

const store = useDocumentStore()
const toast = useToast()
const trixRef = ref<any>(null)
const editTitle = ref('')
const editDescription = ref('')
const isSaving = ref(false)
const error = ref<string | null>(null)

watch(() => props.doc, (doc) => {
  if (doc) {
    editTitle.value = doc.title
    editDescription.value = doc.description || ''
    error.value = null
    nextTick(() => {
      const el = trixRef.value as any
      if (el?.editor) el.editor.loadHTML(editDescription.value)
    })
  }
}, { immediate: true })

function onTrixChange(event: Event) {
  const el = event.target as any
  editDescription.value = el.editor?.getDocument().toString() || ''
}

async function handleSave() {
  if (!props.doc) return
  isSaving.value = true
  error.value = null
  try {
    const trixEl = trixRef.value as any
    const html = trixEl?.editor?.getDocument().toHTML() || editDescription.value || null

    await store.updateDocument(props.doc.id, {
      title: editTitle.value,
      description: html,
    })
    toast.success('Document saved')
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Failed to save'
  } finally {
    isSaving.value = false
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function statusVariant(status: string): 'warning' | 'brand' | 'success' | 'danger' | 'neutral' {
  const map: Record<string, 'warning' | 'brand' | 'success' | 'danger' | 'neutral'> = {
    pending: 'warning',
    processing: 'brand',
    completed: 'success',
    failed: 'danger',
  }
  return map[status] ?? 'neutral'
}
</script>
