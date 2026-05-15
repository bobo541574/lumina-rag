import { get, post, put, del } from './api'
import type { ApiResponse, AiModel } from '../types'

export interface SettingDefinition {
  label: string
  group: string
  type: string
  options?: string[]
}

export interface Setting {
  id: string
  key: string
  value: string
  type: string
  label: string
  group: string
}

interface SettingsData {
  settings: Setting[]
  definitions: Record<string, SettingDefinition>
}

export const settingsService = {
  async getAll() {
    return get<SettingsData>('/settings')
  },

  async update(key: string, value: string, type?: string) {
    return put<Setting>(`/settings/${encodeURIComponent(key)}`, { value, type })
  },

  async delete(key: string) {
    return del(`/settings/${encodeURIComponent(key)}`)
  },

  async bulkUpdate(settings: Record<string, { value: string; type?: string }>) {
    return put<Record<string, { success: boolean }>>('/settings/bulk', { settings })
  },

  // AI Models
  async getAiModels(type?: string) {
    const params = type ? { type } : undefined
    return get<AiModel[]>('/settings/ai-models', params)
  },

  async getAiModel(id: string) {
    return get<AiModel>(`/settings/ai-models/${id}`)
  },

  async createAiModel(data: Partial<AiModel>) {
    return post<AiModel>('/settings/ai-models', data)
  },

  async updateAiModel(id: string, data: Partial<AiModel>) {
    return put<AiModel>(`/settings/ai-models/${id}`, data)
  },

  async deleteAiModel(id: string) {
    return del(`/settings/ai-models/${id}`)
  },
}
