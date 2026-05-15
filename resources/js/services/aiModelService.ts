import { get, post, put, del } from './api'
import type { ApiResponse, AiModel } from '../types'

export const aiModelService = {
  async getAll(type?: string) {
    const params = type ? { type } : undefined
    return get<AiModel[]>('/settings/ai-models', params)
  },

  async get(id: string) {
    return get<AiModel>(`/settings/ai-models/${id}`)
  },

  async create(data: Partial<AiModel>) {
    return post<AiModel>('/settings/ai-models', data)
  },

  async update(id: string, data: Partial<AiModel>) {
    return put<AiModel>(`/settings/ai-models/${id}`, data)
  },

  async delete(id: string) {
    return del(`/settings/ai-models/${id}`)
  },
}
