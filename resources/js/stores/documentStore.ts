import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Document } from '../types'
import { documentService } from '../services/documentService'

export const useDocumentStore = defineStore('document', () => {
  const documents = ref<Document[]>([])
  const currentDocument = ref<Document | null>(null)
  const isLoading = ref(false)
  const isUploading = ref(false)
  const error = ref<string | null>(null)

  async function fetchDocuments(params?: { status?: string }) {
    isLoading.value = true
    try {
      const response = await documentService.list(params)
      documents.value = response.data?.data ?? []
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

  async function uploadDocument(file: File, title?: string, embeddingModel?: string) {
    isUploading.value = true
    error.value = null
    try {
      const response = await documentService.upload(file, title, embeddingModel)
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
    fetchDocuments,
    fetchDocument,
    uploadDocument,
    retryDocument,
    deleteDocument,
    clearError,
  }
})
