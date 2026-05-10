<template>
  <div class="mb-6">
    <div
      @drop.prevent="handleDrop"
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      :class="[
        'border-2 border-dashed rounded-lg p-8 text-center transition-colors',
        isDragging ? 'border-blue-500 bg-blue-50' : 'border-gray-300 hover:border-gray-400'
      ]"
    >
      <input
        ref="fileInput"
        type="file"
        accept=".pdf,.docx,.txt,.csv,.md"
        class="hidden"
        @change="handleFileSelect"
      />
      <div v-if="!isUploading">
        <p class="text-gray-600 mb-2">Drag & drop a document here, or</p>
        <button @click="fileInput?.click()" class="text-blue-600 font-medium hover:text-blue-700">
          Browse files
        </button>
        <p class="text-xs text-gray-400 mt-2">PDF, DOCX, TXT, CSV, MD — Max 50MB</p>
      </div>
      <div v-else class="flex items-center justify-center gap-2">
        <div class="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
        <span class="text-gray-600">Uploading...</span>
      </div>
    </div>

    <div v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useDocumentStore } from '../stores/documentStore'

const store = useDocumentStore()
const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const isUploading = ref(false)
const error = ref<string | null>(null)

async function handleFile(file: File) {
  const allowedTypes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'text/csv', 'text/markdown']
  const maxSize = 50 * 1024 * 1024

  if (!allowedTypes.includes(file.type) && !file.name.match(/\.(pdf|docx|txt|csv|md)$/i)) {
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
    await store.uploadDocument(file)
  } catch {
    error.value = store.error
  } finally {
    isUploading.value = false
  }
}

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
