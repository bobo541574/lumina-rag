<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-lg font-semibold text-gray-900">Settings</h2>
      <button
        v-if="hasChanges"
        @click="saveAll"
        :disabled="store.isSaving"
        class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50"
      >
        {{ store.isSaving ? 'Saving...' : 'Save All' }}
      </button>
    </div>

    <div v-if="store.isLoading" class="text-center text-gray-500 py-12">Loading settings...</div>

    <div v-else class="space-y-6">
      <div v-for="(groupSettings, group) in store.grouped" :key="group" class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
          <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">{{ groupLabel(group) }}</h3>
        </div>
        <div class="divide-y divide-gray-100">
          <div v-for="setting in groupSettings" :key="setting.key" class="px-4 py-3 flex items-center justify-between gap-4">
            <div class="flex-1 min-w-0">
              <label class="block text-sm font-medium text-gray-700">{{ setting.label }}</label>
              <span class="text-xs text-gray-400 font-mono">{{ setting.key }}</span>
            </div>
            <div class="flex items-center gap-2">
              <select
                v-if="def(setting.key)?.options?.length"
                :value="setting.value"
                @change="onChange(setting.key, ($event.target as HTMLSelectElement).value)"
                class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
              >
                <option v-for="opt in def(setting.key)?.options" :key="opt" :value="opt">{{ opt }}</option>
              </select>
              <input
                v-else-if="setting.type === 'bool'"
                type="checkbox"
                :checked="setting.value === 'true'"
                @change="onChange(setting.key, ($event.target as HTMLInputElement).checked ? 'true' : 'false')"
                class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
              />
              <input
                v-else
                :type="setting.type === 'int' || setting.type === 'float' ? 'number' : 'text'"
                :value="setting.value"
                @input="onChange(setting.key, ($event.target as HTMLInputElement).value)"
                :step="setting.type === 'float' ? '0.01' : '1'"
                class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-48 focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
              />
              <button
                v-if="edits[setting.key] !== undefined"
                @click="resetEdit(setting.key)"
                class="text-gray-400 hover:text-gray-600 text-xs"
                title="Reset"
              >&#x2716;</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="store.error" class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
      {{ store.error }}
      <button @click="store.clearError()" class="ml-2 text-red-500 hover:text-red-700">&times;</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useSettingsStore } from '../stores/settingsStore'
import type { SettingDefinition } from '../services/settingsService'

const store = useSettingsStore()
const edits = ref<Record<string, string>>({})

const hasChanges = computed(() => Object.keys(edits.value).length > 0)

function def(key: string): SettingDefinition | undefined {
  return store.definitions[key]
}

function groupLabel(group: string): string {
  const map: Record<string, string> = {
    embedding: 'Embedding',
    llm: 'LLM',
    vector_store: 'Vector Store',
    search: 'Search',
    chunking: 'Chunking',
    chat: 'Chat',
    logging: 'Logging',
    general: 'General',
  }
  return map[group] ?? group
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
  } catch {
    // error shown via store
  }
}

onMounted(() => {
  store.fetch()
})
</script>
