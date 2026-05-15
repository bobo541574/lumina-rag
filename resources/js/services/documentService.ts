import { get, post, put, del, upload } from './api'
import type { Document } from '../types'

export const documentService = {
  async list(params?: { status?: string; per_page?: number }) {
    return get<{ data: Document[] }>('/documents', params)
  },

  async getDocument(id: string) {
    return get<Document>(`/documents/${id}`)
  },

  async getStatus(id: string) {
    return get<{ id: string; status: string; chunks_count: number }>(`/documents/${id}/status`)
  },

  async upload(file: File, title?: string, embeddingModelId?: string) {
    const formData = new FormData()
    formData.append('file', file)
    if (title) formData.append('title', title)
    if (embeddingModelId) formData.append('embedding_model_id', embeddingModelId)
    return upload<Document>('/documents', formData)
  },

  async update(id: string, data: { title?: string; description?: string }) {
    return put<Document>(`/documents/${id}`, data)
  },

  async retry(id: string) {
    return post<Document>(`/documents/${id}/retry`)
  },

  async delete(id: string) {
    return del(`/documents/${id}`)
  },
}
