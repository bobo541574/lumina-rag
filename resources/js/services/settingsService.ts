import { get, put, del } from './api'
import type { ApiResponse } from '../types'

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
}
