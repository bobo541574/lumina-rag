<template>
  <div>
    <h2 class="text-lg font-semibold text-surface-900 mb-6">Settings</h2>

    <!-- RAG Settings Section -->
    <div>
      <div class="flex items-center justify-between mb-4 gap-4 flex-wrap">
        <h3 class="text-sm font-semibold text-surface-700 uppercase tracking-wider">RAG Configuration</h3>
        <AppButton
          v-if="hasChanges"
          variant="primary"
          :loading="store.isSaving"
          loading-label="Saving…"
          @click="saveAll"
        >
          Save All
        </AppButton>
      </div>

      <!-- Skeleton loader -->
      <div v-if="store.isLoading" class="space-y-6" aria-busy="true" aria-label="Loading settings">
        <div v-for="g in 3" :key="g" class="bg-white border border-surface-200 rounded-card overflow-hidden">
          <div class="px-4 py-3 bg-surface-50 border-b border-surface-200">
            <div class="h-4 w-32 bg-surface-200 rounded animate-pulse" />
          </div>
          <div class="divide-y divide-surface-100">
            <div v-for="r in 3" :key="r" class="px-4 py-4 flex items-center justify-between gap-4">
              <div class="flex-1 space-y-2">
                <div class="h-4 w-40 bg-surface-200 rounded animate-pulse" />
                <div class="h-3 w-24 bg-surface-100 rounded animate-pulse" />
              </div>
              <div class="h-9 w-48 bg-surface-200 rounded-lg animate-pulse" />
            </div>
          </div>
        </div>
      </div>

      <div v-else class="space-y-6">
        <div v-for="(groupSettings, group) in store.grouped" :key="group" class="bg-white border border-surface-200 rounded-card overflow-hidden">
          <div class="px-4 py-3 bg-surface-50 border-b border-surface-200">
            <h3 class="text-sm font-semibold text-surface-700 uppercase tracking-wider">{{ groupLabel(group) }}</h3>
            <p v-if="groupDescription(group)" class="text-xs text-surface-500 mt-0.5 normal-case tracking-normal font-normal">
              {{ groupDescription(group) }}
            </p>
          </div>
          <div class="divide-y divide-surface-100">
            <div v-for="setting in groupSettings" :key="setting.key" class="px-4 py-3 flex items-center justify-between gap-4">
              <div class="flex-1 min-w-0">
                <label class="block text-sm font-medium text-surface-700">
                  {{ setting.label }}
                  <span v-if="edits[setting.key] !== undefined" class="ml-1 inline-block w-1.5 h-1.5 rounded-full bg-warning-600 align-middle" :title="'Unsaved change'" aria-label="unsaved change" />
                </label>
                <span class="text-xs text-surface-400 font-mono">{{ setting.key }}</span>
              </div>
              <div class="flex items-center gap-2">
                <AppSelect
                  v-if="def(setting.key)?.options?.length"
                  :model-value="setting.value"
                  @update:model-value="(val) => onChange(setting.key, val)"
                >
                  <option v-for="opt in def(setting.key)?.options" :key="opt" :value="opt">{{ opt }}</option>
                </AppSelect>
                <AppCheckbox
                  v-else-if="setting.type === 'bool'"
                  :model-value="setting.value === 'true'"
                  @update:model-value="(val) => onChange(setting.key, val ? 'true' : 'false')"
                />
                <AppInput
                  v-else-if="setting.type === 'json'"
                  :model-value="setting.value"
                  @update:model-value="(val) => onChange(setting.key, val)"
                  class="font-mono text-xs w-64"
                />
                <AppInput
                  v-else
                  :type="setting.type === 'int' || setting.type === 'float' ? 'number' : 'text'"
                  :model-value="setting.value"
                  @update:model-value="(val) => onChange(setting.key, val)"
                  :step="setting.type === 'float' ? '0.01' : '1'"
                  class="w-48"
                />
                <AppButton
                  v-if="edits[setting.key] !== undefined"
                  variant="ghost"
                  size="sm"
                  aria-label="Reset change"
                  title="Reset change"
                  class="!p-1.5"
                  @click="resetEdit(setting.key)"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4v5h5M3.05 13a9 9 0 105.6-7.95L3 9" />
                  </svg>
                </AppButton>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="store.error" class="mt-4 p-3 bg-danger-50 border border-danger-200 rounded-card text-sm text-danger-700 flex items-start justify-between gap-2" role="alert">
      <span>{{ store.error }}</span>
      <AppButton variant="ghost" size="sm" aria-label="Dismiss error" class="!p-1" @click="store.clearError()">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </AppButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useSettingsStore } from '../stores/settingsStore'
import type { SettingDefinition } from '../services/settingsService'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppCheckbox from '../components/ui/AppCheckbox.vue'
import AppButton from '../components/ui/AppButton.vue'
import { useToast } from '../composables/useToast'

const store = useSettingsStore()
const toast = useToast()
const edits = ref<Record<string, string>>({})

const hasChanges = computed(() => Object.keys(edits.value).length > 0)

function def(key: string): SettingDefinition | undefined {
  return store.definitions[key]
}

const GROUP_LABELS: Record<string, string> = {
  embedding: 'Embedding',
  llm: 'LLM',
  vector_store: 'Vector Store',
  search: 'Search',
  chunking: 'Chunking',
  chat: 'Chat',
  logging: 'Logging',
  general: 'General',
}

const GROUP_DESCRIPTIONS: Record<string, string> = {
  embedding: 'How text gets converted into vectors for retrieval.',
  llm: 'Language model used to generate answers.',
  vector_store: 'Where vectors are stored and indexed.',
  search: 'How relevant chunks are retrieved at query time.',
  chunking: 'How documents are split before embedding.',
  chat: 'Limits on chat sessions and messages.',
  logging: 'Application logging behavior.',
  general: 'General application settings.',
}

function groupLabel(group: string): string {
  return GROUP_LABELS[group] ?? group
}

function groupDescription(group: string): string {
  return GROUP_DESCRIPTIONS[group] ?? ''
}

function onChange(key: string, value: string) {
  edits.value[key] = value
}

function resetEdit(key: string) {
  delete edits.value[key]
}

async function saveAll() {
  const data: Record<string, { value: string; type?: string }> = {}
  for (const [key, value] of Object.entries(edits.value)) {
    const defn = def(key)
    data[key] = { value, type: defn?.type }
  }
  try {
    await store.bulkUpdate(data)
    edits.value = {}
    toast.success('Settings saved')
  } catch {
    if (store.error) toast.error(store.error)
  }
}

onMounted(() => {
  store.fetch()
})
</script>
