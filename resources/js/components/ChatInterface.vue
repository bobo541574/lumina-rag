<template>
  <div class="flex flex-col h-full">
    <div class="border-b border-gray-200 bg-gray-50 px-4 py-2">
      <button @click="showFilters = !showFilters" class="flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
        </svg>
        <span>Search Filters</span>
        <span v-if="activeFilterCount > 0" class="bg-blue-600 text-white text-xs rounded-full px-1.5 py-0.5">{{ activeFilterCount }}</span>
      </button>

      <div v-if="showFilters" class="mt-2 space-y-2">
        <div v-if="documents.length > 0">
          <p class="text-xs font-medium text-gray-500 mb-1">Documents</p>
          <div class="flex flex-wrap gap-2">
            <label v-for="doc in documents" :key="doc.id"
              class="flex items-center gap-1.5 text-xs px-2 py-1 rounded border cursor-pointer"
              :class="selectedDocIds.includes(doc.id) ? 'bg-blue-50 border-blue-300 text-blue-700' : 'bg-white border-gray-200 text-gray-600 hover:border-gray-300'"
            >
              <input type="checkbox" :value="doc.id" v-model="selectedDocIds" class="sr-only" />
              {{ doc.title || doc.original_filename }}
            </label>
          </div>
        </div>

        <div class="flex gap-4">
          <div>
            <label class="text-xs font-medium text-gray-500 block mb-1">From</label>
            <input type="date" v-model="dateFrom" class="text-xs rounded border border-gray-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-500" />
          </div>
          <div>
            <label class="text-xs font-medium text-gray-500 block mb-1">To</label>
            <input type="date" v-model="dateTo" class="text-xs rounded border border-gray-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-500" />
          </div>
          <div class="flex items-end">
            <button @click="clearFilters" class="text-xs text-gray-500 hover:text-red-600 underline">Clear</button>
          </div>
        </div>
        <div class="flex gap-4 mt-2 pt-2 border-t border-gray-100">
          <div>
            <label class="text-xs font-medium text-gray-500 block mb-1">LLM Model</label>
            <select v-model="selectedLlmModel"
              class="text-xs rounded border border-gray-300 px-2 py-1 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option v-for="m in llmModelOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div ref="messagesContainer" class="flex-1 overflow-y-auto space-y-4 p-4">
      <div v-if="messages.length === 0 && !isLoading" class="text-center text-gray-500 py-12">
        <p class="text-lg">Ask a question about your documents</p>
      </div>

      <div v-for="msg in messages" :key="msg.id" :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']">
        <div :class="['max-w-2xl rounded-lg px-4 py-3', msg.role === 'user' ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200']">
          <p class="text-sm whitespace-pre-wrap">{{ msg.content }}</p>
          <div v-if="msg.sources && msg.sources.length > 0" class="mt-2 pt-2 border-t border-gray-200">
            <p class="text-xs font-medium text-gray-500 mb-1">Sources:</p>
            <div v-for="(source, idx) in msg.sources" :key="idx" class="text-xs text-gray-400 mb-1">
              <span class="font-medium">{{ source.document_title }}</span>
              <span v-if="source.page_number"> — p.{{ source.page_number }}</span>
              <span> ({{ Math.round(source.similarity_score * 100) }}%)</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="isStreaming && messages.length > 0" class="flex justify-start">
        <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
          <span class="inline-block w-2 h-4 bg-blue-600 animate-pulse"></span>
        </div>
      </div>

      <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
        {{ error }}
      </div>
    </div>

    <div class="border-t border-gray-200 p-4 bg-white">
      <form @submit.prevent="handleSubmit" class="flex gap-2">
        <input
          v-model="question"
          type="text"
          placeholder="Ask a question..."
          class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          :disabled="isLoading || isStreaming"
          maxlength="1000"
        />
        <button
          v-if="isStreaming"
          type="button"
          @click="store.abortStream()"
          class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700"
        >
          Stop
        </button>
        <button
          v-else
          type="submit"
          :disabled="isLoading || !question.trim()"
          class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          Send
        </button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { useChatStore } from '../stores/chatStore'
import { useDocumentStore } from '../stores/documentStore'
import { useSettingsStore } from '../stores/settingsStore'
import { storeToRefs } from 'pinia'

const store = useChatStore()
const docStore = useDocumentStore()
const settingsStore = useSettingsStore()
const { messages, isLoading, isStreaming, error } = storeToRefs(store)
const { documents } = storeToRefs(docStore)

const question = ref('')
const messagesContainer = ref<HTMLElement | null>(null)
const showFilters = ref(false)
const selectedDocIds = ref<string[]>([])
const dateFrom = ref('')
const dateTo = ref('')
const selectedLlmModel = ref('')

const llmModels = computed(() => {
  const raw = settingsStore.getSettingValue('RAG_LLM_AVAILABLE_MODELS')
  if (Array.isArray(raw)) return raw as string[]
  return []
})

const defaultLlmModel = computed(() => {
  return settingsStore.getSettingValue('RAG_LLM_MODEL') ?? ''
})

const llmModelOptions = computed(() => {
  const def = defaultLlmModel.value
  return [
    { value: '', label: `From settings (${def || 'default'})` },
    ...llmModels.value.map((m: string) => ({ value: m, label: m })),
  ]
})

const activeFilterCount = computed(() => {
  let count = 0
  if (selectedDocIds.value.length > 0) count++
  if (dateFrom.value || dateTo.value) count++
  return count
})

onMounted(() => {
  docStore.fetchDocuments()
  settingsStore.fetch()
})

watch(messages, async () => {
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}, { deep: true })

function buildFilter(): Record<string, unknown> | null {
  const filter: Record<string, unknown> = {}
  if (selectedDocIds.value.length > 0) {
    filter.document_ids = selectedDocIds.value
  }
  if (dateFrom.value) {
    filter.date_from = dateFrom.value
  }
  if (dateTo.value) {
    filter.date_to = dateTo.value
  }
  return Object.keys(filter).length > 0 ? filter : null
}

function clearFilters() {
  selectedDocIds.value = []
  dateFrom.value = ''
  dateTo.value = ''
  store.setDocumentFilter(null)
}

async function handleSubmit() {
  if (!question.value.trim() || isLoading.value) return
  const q = question.value
  question.value = ''
  const filter = buildFilter()
  store.setDocumentFilter(filter)
  await store.sendMessage(q, filter ?? undefined)
}
</script>
