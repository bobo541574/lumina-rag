import { get, del, upload } from './api'
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

  async upload(file: File, title?: string) {
    const formData = new FormData()
    formData.append('file', file)
    if (title) formData.append('title', title)
    return upload<Document>('/documents', formData)
  },

  async delete(id: string) {
    return del(`/documents/${id}`)
  },
}
