import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { Setting, SettingDefinition } from '../services/settingsService'
import { settingsService } from '../services/settingsService'

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref<Setting[]>([])
  const definitions = ref<Record<string, SettingDefinition>>({})
  const isLoading = ref(false)
  const isSaving = ref(false)
  const error = ref<string | null>(null)

  const grouped = computed(() => {
    const groups: Record<string, Setting[]> = {}
    for (const s of settings.value) {
      const g = s.group
      if (!groups[g]) groups[g] = []
      groups[g].push(s)
    }
    return groups
  })

  async function fetch() {
    isLoading.value = true
    try {
      const res = await settingsService.getAll()
      settings.value = res.data?.settings ?? []
      definitions.value = res.data?.definitions ?? {}
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to load settings'
    } finally {
      isLoading.value = false
    }
  }

  async function updateSetting(key: string, value: string, type?: string) {
    isSaving.value = true
    try {
      await settingsService.update(key, value, type)
      const idx = settings.value.findIndex(s => s.key === key)
      if (idx !== -1) settings.value[idx].value = value
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to update setting'
      throw e
    } finally {
      isSaving.value = false
    }
  }

  async function bulkUpdate(data: Record<string, { value: string; type?: string }>) {
    isSaving.value = true
    try {
      await settingsService.bulkUpdate(data)
      for (const key of Object.keys(data)) {
        const idx = settings.value.findIndex(s => s.key === key)
        if (idx !== -1) settings.value[idx].value = data[key].value
      }
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to update settings'
      throw e
    } finally {
      isSaving.value = false
    }
  }

  async function resetSetting(key: string) {
    try {
      await settingsService.delete(key)
      settings.value = settings.value.filter(s => s.key !== key)
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to reset setting'
    }
  }

  function getSettingValue(key: string): string | string[] | null {
    const s = settings.value.find(s => s.key === key)
    if (!s) return null
    if (s.type === 'json') {
      try { return JSON.parse(s.value) } catch { return s.value }
    }
    return s.value
  }

  function clearError() {
    error.value = null
  }

  return {
    settings, definitions, grouped, isLoading, isSaving, error,
    getSettingValue,
    fetch, updateSetting, bulkUpdate, resetSetting, clearError,
  }
})
