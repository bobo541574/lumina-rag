import { get, post, del, upload } from './api'
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

  async upload(file: File, title?: string, embeddingModel?: string) {
    const formData = new FormData()
    formData.append('file', file)
    if (title) formData.append('title', title)
    if (embeddingModel) formData.append('embedding_model', embeddingModel)
    return upload<Document>('/documents', formData)
  },

  async retry(id: string) {
    return post<Document>(`/documents/${id}/retry`)
  },

  async delete(id: string) {
    return del(`/documents/${id}`)
  },
}
