import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Document, PaginationMeta } from '../types'
import { documentService } from '../services/documentService'

export const useDocumentStore = defineStore('document', () => {
  const documents = ref<Document[]>([])
  const currentDocument = ref<Document | null>(null)
  const isLoading = ref(false)
  const isUploading = ref(false)
  const error = ref<string | null>(null)
  const meta = ref<PaginationMeta | null>(null)

  async function fetchDocuments(params?: {
    status?: string
    per_page?: number
    page?: number
    search?: string
    sort_key?: string
    sort_dir?: string
  }) {
    isLoading.value = true
    try {
      const response = await documentService.list(params)
      const payload = response.data
      documents.value = payload.data ?? []
      meta.value = payload.meta ?? null
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to load documents'
    } finally {
      isLoading.value = false
    }
  }

  async function fetchDocument(id: string) {
    isLoading.value = true
    try {
      const response = await documentService.getDocument(id)
      currentDocument.value = response.data
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Document not found'
    } finally {
      isLoading.value = false
    }
  }

  async function uploadDocument(file: File, title?: string, embeddingModelId?: string) {
    isUploading.value = true
    error.value = null
    try {
      const response = await documentService.upload(file, title, embeddingModelId)
      documents.value.unshift(response.data)
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Upload failed'
      throw e
    } finally {
      isUploading.value = false
    }
  }

  async function retryDocument(id: string) {
    isLoading.value = true
    error.value = null
    try {
      const response = await documentService.retry(id)
      const idx = documents.value.findIndex(d => d.id === id)
      if (idx !== -1) {
        documents.value[idx] = response.data
      }
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Retry failed'
    } finally {
      isLoading.value = false
    }
  }

  async function updateDocument(id: string, data: { title?: string; description?: string }) {
    try {
      const response = await documentService.update(id, data)
      const idx = documents.value.findIndex(d => d.id === id)
      if (idx !== -1) {
        documents.value[idx] = { ...documents.value[idx], ...response.data }
      }
      if (currentDocument.value?.id === id) {
        currentDocument.value = { ...currentDocument.value, ...response.data }
      }
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Update failed'
      throw e
    }
  }

  async function deleteDocument(id: string) {
    try {
      await documentService.delete(id)
      documents.value = documents.value.filter(d => d.id !== id)
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Delete failed'
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    documents,
    currentDocument,
    isLoading,
    isUploading,
    error,
    meta,
    fetchDocuments,
    fetchDocument,
    uploadDocument,
    updateDocument,
    retryDocument,
    deleteDocument,
    clearError,
  }
})
