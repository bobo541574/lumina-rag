import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Document, PaginationMeta } from '../types'
import { documentService } from '../services/documentService'

/**
 * Document Store
 *
 * Manages document list, current document detail, upload state, and CRUD
 * operations. Coordinates with the Document API service and keeps local
 * state in sync after mutations.
 */
export const useDocumentStore = defineStore('document', () => {
  const documents = ref<Document[]>([])
  const currentDocument = ref<Document | null>(null)
  const isLoading = ref(false)
  const isUploading = ref(false)
  const error = ref<string | null>(null)
  const meta = ref<PaginationMeta | null>(null)

  /**
   * Fetch documents with filtering, sorting, search, and pagination
   *
   * Replaces the full document list with the server response.
   *
   * @param {Object} [params] Query parameters
   * @param {string} [params.status] Status filter. Example: "completed"
   * @param {number} [params.per_page] Items per page. Example: 25
   * @param {number} [params.page] Page number. Example: 1
   * @param {string} [params.search] Search query. Example: "report"
   * @param {string} [params.sort_key] Sort column. Example: "created_at"
   * @param {string} [params.sort_dir] Sort direction. Example: "desc"
   */
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
      documents.value = response.data ?? []
      meta.value = response.meta ?? null
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to load documents'
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Fetch a single document by ID
   *
   * @param {string} id Document ULID. Example: "01J..."
   */
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

  /**
   * Upload a new document
   *
   * Prepends the created document to the local list on success.
   *
   * @param {File} file File to upload. Example: new File(["..."], "report.pdf")
   * @param {string} [title] Display title. Example: "Q3 Report"
   * @param {string} [embeddingModelId] Override embedding model ULID. Example: "01J..."
   * @param {string} [reportDate] Report date (Y-m-d). Example: "2026-03-15"
   * @param {string} [project] Project label. Example: "Project Orion"
   * @returns {Promise<Document>} The created document. Example: { id: "01J...", status: "pending", ... }
   * @throws When upload fails
   */
  async function uploadDocument(file: File, title?: string, embeddingModelId?: string, reportDate?: string, project?: string) {
    isUploading.value = true
    error.value = null
    try {
      const response = await documentService.upload(file, title, embeddingModelId, reportDate, project)
      documents.value.unshift(response.data)
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Upload failed'
      throw e
    } finally {
      isUploading.value = false
    }
  }

  /**
   * Retry processing for a failed document
   *
   * Updates the document in-place in the list.
   *
   * @param {string} id Document ULID. Example: "01J..."
   */
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

  /**
   * Update document metadata
   *
   * Updates both the list entry and currentDocument if they match.
   *
   * @param {string} id Document ULID. Example: "01J..."
   * @param {Object} data Fields to update
   * @param {string} [data.title] New title. Example: "Updated Title"
   * @param {string} [data.description] Rich-text description. Example: "<div>...</div>"
   * @param {string} [data.report_date] Report date. Example: "2026-03-15"
   * @param {string} [data.project] Project label. Example: "Project Orion"
   * @returns {Promise<Document>} The updated document. Example: { id: "01J...", title: "Updated Title", ... }
   * @throws When update fails
   */
  async function updateDocument(id: string, data: { title?: string; description?: string; report_date?: string; project?: string }) {
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

  /**
   * Delete a document
   *
   * Removes from the local list on success.
   *
   * @param {string} id Document ULID. Example: "01J..."
   */
  async function deleteDocument(id: string) {
    try {
      await documentService.delete(id)
      documents.value = documents.value.filter(d => d.id !== id)
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Delete failed'
    }
  }

  /**
   * Clear the current error state
   */
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
