<template>
  <div class="mb-6">
    <div
      :class="[
        'border-2 border-dashed rounded-card transition-colors',
        isDragging ? 'border-brand-500 bg-brand-50' : 'border-surface-300 hover:border-surface-400',
      ]"
    >
      <div
        v-if="!isUploading"
        role="button"
        tabindex="0"
        aria-label="Upload document — click to browse or drag and drop a file"
        class="p-8 text-center cursor-pointer rounded-t-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
        @click="openFilePicker"
        @keydown.enter.prevent="openFilePicker"
        @keydown.space.prevent="openFilePicker"
        @drop.prevent="handleDrop"
        @dragover.prevent="setDragging(true)"
        @dragleave.prevent="setDragging(false)"
      >
        <svg class="w-10 h-10 mx-auto text-surface-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4" />
        </svg>
        <p class="text-surface-600 mb-2">Drag & drop a document here, or</p>
        <AppButton variant="primary" size="md" @click.stop="openFilePicker">
          Browse files
        </AppButton>
        <p class="text-xs text-surface-400 mt-2">PDF, DOCX, TXT, CSV, MD — Max 50MB</p>
      </div>

      <div v-else class="p-8 flex items-center justify-center gap-2" role="status" aria-live="polite">
        <AppSpinner size="md" />
        <span class="text-surface-600">Uploading…</span>
      </div>

      <div class="px-6 py-4 border-t border-surface-200 bg-white rounded-b-card">
        <label class="text-sm text-surface-600 font-medium">Embedding Model</label>
        <div class="mt-1 flex items-center gap-2">
          <AppSelect v-model="selectedModelId">
            <option value="">Default (auto-select)</option>
            <option v-for="m in embeddingModels" :key="m.id" :value="m.id">{{ m.name }}</option>
          </AppSelect>
          <span v-if="selectedModelId && selectedModel" class="text-xs text-surface-500">
            ({{ selectedModel.provider }} / {{ selectedModel.model }}{{ selectedModel.dimensions ? `, ${selectedModel.dimensions}d` : '' }})
          </span>
        </div>
        <div v-if="selectedModelId && selectedModel?.description" class="mt-2 text-xs text-surface-500 bg-surface-50 border border-surface-200 rounded-card px-3 py-2">
          {{ selectedModel.description }}
        </div>
      </div>

      <input
        ref="fileInput"
        type="file"
        accept=".pdf,.docx,.txt,.csv,.md"
        class="hidden"
        @change="handleFileSelect"
      />
    </div>

    <div v-if="error" class="mt-2 text-sm text-danger-600" role="alert">{{ error }}</div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import AppSelect from './ui/AppSelect.vue'
import AppButton from './ui/AppButton.vue'
import AppSpinner from './ui/AppSpinner.vue'
import { useDocumentStore } from '../stores/documentStore'
import { useToast } from '../composables/useToast'
import { aiModelService } from '../services/aiModelService'
import type { AiModel } from '../types'

const emit = defineEmits<{
  uploaded: []
}>()

const store = useDocumentStore()
const toast = useToast()
const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const isUploading = ref(false)
const error = ref<string | null>(null)
const selectedModelId = ref('')
const embeddingModels = ref<AiModel[]>([])
const selectedModel = computed(() => embeddingModels.value.find(m => m.id === selectedModelId.value))

const allowedTypes = [
  'application/pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'text/plain',
  'text/csv',
  'text/markdown',
]
const allowedExt = /\.(pdf|docx|txt|csv|md)$/i
const maxSize = 50 * 1024 * 1024

function openFilePicker() {
  if (isUploading.value) return
  fileInput.value?.click()
}

function setDragging(value: boolean) {
  if (isUploading.value) return
  isDragging.value = value
}

async function handleFile(file: File) {
  if (!allowedTypes.includes(file.type) && !file.name.match(allowedExt)) {
    error.value = 'File type not supported. Allowed: PDF, DOCX, TXT, CSV, MD'
    return
  }
  if (file.size > maxSize) {
    error.value = 'File size exceeds 50MB limit'
    return
  }

  error.value = null
  isUploading.value = true
  try {
    await store.uploadDocument(file, undefined, selectedModelId.value || undefined)
    toast.success(`"${file.name}" uploaded — processing started`)
    emit('uploaded')
  } catch {
    error.value = store.error
    if (store.error) toast.error(store.error)
  } finally {
    isUploading.value = false
    isDragging.value = false
  }
}

onMounted(async () => {
  try {
    const res = await aiModelService.getAll('embedding')
    embeddingModels.value = res.data ?? []
  } catch {
    // fall back to default (empty) selection
  }
})

function handleDrop(event: DragEvent) {
  isDragging.value = false
  const file = event.dataTransfer?.files[0]
  if (file) handleFile(file)
}

function handleFileSelect(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files?.[0]) handleFile(input.files[0])
  if (fileInput.value) fileInput.value.value = ''
}
</script>
