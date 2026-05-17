import { get, post, put, del, upload } from './api'
import type { Document } from '../types'

/**
 * Document API service
 *
 * CRUD operations for documents — upload, list, update, retry processing,
 * and delete. Documents are files ingested by the RAG pipeline.
 */
export const documentService = {
  /**
   * List documents with filtering, sorting, search, and pagination
   *
   * @param {Object} [params] Query parameters
   * @param {string} [params.status] Filter by status. Example: "completed"
   * @param {number} [params.per_page] Items per page. Example: 25
   * @param {number} [params.page] Page number (1-based). Example: 1
   * @param {string} [params.search] Search by title or filename. Example: "Q3 report"
   * @param {string} [params.sort_key] Sort column. Example: "created_at"
   * @param {string} [params.sort_dir] Sort direction: "asc" | "desc". Example: "desc"
   * @returns {Promise<ApiResponse<Document[]>>} Paginated list of documents. Example: { success: true, data: [{ id: "01J...", ... }], meta: {...} }
   */
  async list(params?: {
    status?: string
    per_page?: number
    page?: number
    search?: string
    sort_key?: string
    sort_dir?: string
  }) {
    return get<Document[]>('/documents', params)
  },

  /**
   * Get a single document by ID
   *
   * @param {string} id Document ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<Document>>} Full document record. Example: { success: true, data: { id: "01J...", title: "...", ... } }
   */
  async getDocument(id: string) {
    return get<Document>(`/documents/${id}`)
  },

  /**
   * Get document processing status
   *
   * Lightweight endpoint returning just the status and chunk count.
   *
   * @param {string} id Document ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<{ id: string; status: string; chunks_count: number }>>} Status info. Example: { success: true, data: { id: "01J...", status: "completed", chunks_count: 24 } }
   */
  async getStatus(id: string) {
    return get<{ id: string; status: string; chunks_count: number }>(`/documents/${id}/status`)
  },

  /**
   * Upload a new document
   *
   * Sends the file as multipart/form-data with optional metadata. The server
   * dispatches a background job to process and embed the document.
   *
   * @param {File} file The file to upload. Example: new File(["..."], "report.pdf")
   * @param {string} [title] Optional display title. Example: "Q3 Report"
   * @param {string} [embeddingModelId] Override embedding model ULID. Example: "01J..."
   * @param {string} [reportDate] Associated report date (Y-m-d). Example: "2026-03-15"
   * @param {string} [project] Project label. Example: "Project Orion"
   * @returns {Promise<ApiResponse<Document>>} The created document record. Example: { success: true, data: { id: "01J...", status: "pending", ... } }
   */
  async upload(file: File, title?: string, embeddingModelId?: string, reportDate?: string, project?: string) {
    const formData = new FormData()
    formData.append('file', file)
    if (title) formData.append('title', title)
    if (embeddingModelId) formData.append('embedding_model_id', embeddingModelId)
    if (reportDate) formData.append('report_date', reportDate)
    if (project) formData.append('project', project)
    return upload<Document>('/documents', formData)
  },

  /**
   * Update document metadata
   *
   * @param {string} id Document ULID. Example: "01J..."
   * @param {Object} data Fields to update
   * @param {string} [data.title] New title. Example: "Updated Title"
   * @param {string} [data.description] Rich-text description (HTML). Example: "<div>Description</div>"
   * @param {string} [data.report_date] Report date (Y-m-d). Example: "2026-03-15"
   * @param {string} [data.project] Project label. Example: "Project Orion"
   * @returns {Promise<ApiResponse<Document>>} Updated document. Example: { success: true, data: { id: "01J...", ... } }
   */
  async update(id: string, data: { title?: string; description?: string; report_date?: string; project?: string }) {
    return put<Document>(`/documents/${id}`, data)
  },

  /**
   * Retry failed document processing
   *
   * Re-dispatches the processing job for a document that failed during
   * extraction or embedding.
   *
   * @param {string} id Document ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<Document>>} The document with status reset. Example: { success: true, data: { id: "01J...", status: "pending", ... } }
   */
  async retry(id: string) {
    return post<Document>(`/documents/${id}/retry`)
  },

  /**
   * Delete a document and its embeddings
   *
   * @param {string} id Document ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<null>>} Empty success response. Example: { success: true, message: "Document deleted" }
   */
  async delete(id: string) {
    return del(`/documents/${id}`)
  },
}
