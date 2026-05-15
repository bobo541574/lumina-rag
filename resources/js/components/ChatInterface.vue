<template>
  <div class="flex flex-col h-full">
    <!-- Filter bar -->
    <div class="border-b border-surface-200 bg-surface-50 px-4 py-2">
      <AppButton variant="ghost" size="sm" @click="showFilters = !showFilters">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
        </svg>
        Search Filters
        <span v-if="activeFilterCount > 0" class="bg-brand-600 text-white text-xs rounded-full px-1.5 py-0.5">{{ activeFilterCount }}</span>
      </AppButton>

      <div v-if="showFilters" class="mt-2 space-y-2">
        <div v-if="documents.length > 0">
          <p class="text-xs font-medium text-surface-500 mb-1">Documents</p>
          <div class="flex flex-wrap gap-2">
            <label
              v-for="doc in documents"
              :key="doc.id"
              class="filter-chip flex items-center gap-1.5 text-xs px-2 py-1 rounded border cursor-pointer transition-colors"
              :class="selectedDocIds.includes(doc.id)
                ? 'bg-brand-50 border-brand-300 text-brand-700'
                : 'bg-white border-surface-200 text-surface-600 hover:border-surface-300'"
            >
              <input type="checkbox" :value="doc.id" v-model="selectedDocIds" class="sr-only" />
              {{ doc.title || doc.original_filename }}
            </label>
          </div>
        </div>

        <div class="flex flex-wrap gap-4">
          <div>
            <label class="text-xs font-medium text-surface-500 block mb-1">From</label>
            <AppInput type="date" v-model="dateFrom" class="text-xs" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-500 block mb-1">To</label>
            <AppInput type="date" v-model="dateTo" class="text-xs" />
          </div>
          <div class="flex items-end">
            <AppButton variant="ghost" size="sm" @click="clearFilters">Clear</AppButton>
          </div>
        </div>

        <div class="flex flex-wrap gap-4 mt-2 pt-2 border-t border-surface-100">
          <div>
            <label class="text-xs font-medium text-surface-500 block mb-1">LLM Model</label>
            <AppSelect v-model="selectedLlmModel">
              <option v-for="m in llmModelOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </AppSelect>
          </div>
        </div>
      </div>
    </div>

    <!-- Messages -->
    <div ref="messagesContainer" class="flex-1 overflow-y-auto space-y-4 p-4">
      <AppEmptyState
        v-if="messages.length === 0 && !isLoading"
        icon="chat"
        title="Ask a question about your documents"
        description="Try “What does the contract say about termination?” or “Summarize the third quarter results.”"
      />

      <div
        v-for="msg in messages"
        :key="msg.id"
        :class="['flex gap-2', msg.role === 'user' ? 'justify-end' : 'justify-start']"
      >
        <!-- Assistant avatar -->
        <div
          v-if="msg.role === 'assistant'"
          class="w-7 h-7 flex-shrink-0 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-semibold mt-0.5"
          aria-hidden="true"
        >L</div>

        <div :class="['max-w-2xl rounded-card px-4 py-3', msg.role === 'user' ? 'bg-brand-600 text-white' : 'bg-white border border-surface-200']">
          <p class="text-sm whitespace-pre-wrap">{{ msg.content }}</p>

          <div v-if="msg.sources && msg.sources.length > 0" class="mt-3 pt-2 border-t border-surface-200">
            <p class="text-xs font-medium text-surface-600 mb-1">Sources</p>
            <ul class="text-xs text-surface-600 space-y-0.5">
              <li v-for="(source, idx) in msg.sources" :key="idx">
                <span class="font-medium text-surface-700">{{ source.document_title }}</span>
                <span v-if="source.page_number" class="text-surface-500"> — p.{{ source.page_number }}</span>
                <span class="text-surface-500"> ({{ Math.round(source.similarity_score * 100) }}%)</span>
              </li>
            </ul>
          </div>

          <p
            v-if="msg.created_at"
            :class="['text-[10px] mt-1.5 tabular-nums', msg.role === 'user' ? 'text-white/70 text-right' : 'text-surface-400']"
          >
            {{ formatRelativeTime(msg.created_at) }}
          </p>
        </div>

        <!-- User avatar -->
        <div
          v-if="msg.role === 'user'"
          class="w-7 h-7 flex-shrink-0 rounded-full bg-surface-200 text-surface-600 flex items-center justify-center text-xs font-semibold mt-0.5"
          aria-hidden="true"
        >U</div>
      </div>

      <!-- Streaming indicator: typing dots -->
      <div v-if="isStreaming && messages.length > 0" class="flex justify-start gap-2">
        <div
          class="w-7 h-7 flex-shrink-0 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-semibold mt-0.5"
          aria-hidden="true"
        >L</div>
        <div class="bg-white border border-surface-200 rounded-card px-4 py-3 flex items-center gap-1" role="status" aria-label="Assistant is typing">
          <span class="typing-dot bg-brand-600 rounded-full w-1.5 h-1.5 inline-block" />
          <span class="typing-dot bg-brand-600 rounded-full w-1.5 h-1.5 inline-block" style="animation-delay: 150ms" />
          <span class="typing-dot bg-brand-600 rounded-full w-1.5 h-1.5 inline-block" style="animation-delay: 300ms" />
        </div>
      </div>

      <div v-if="error" class="bg-danger-50 border border-danger-200 rounded-card px-4 py-3 text-sm text-danger-700 flex items-start justify-between gap-2" role="alert">
        <span>{{ error }}</span>
        <AppButton variant="ghost" size="sm" aria-label="Dismiss error" @click="store.clearError?.()">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </AppButton>
      </div>
    </div>

    <!-- Input area -->
    <div class="border-t border-surface-200 p-4 bg-white">
      <form @submit.prevent="handleSubmit" class="flex gap-2">
        <AppInput
          v-model="question"
          type="text"
          placeholder="Ask a question..."
          class="flex-1"
          :disabled="isLoading || isStreaming"
          :maxlength="MAX_QUESTION_LENGTH"
          :aria-describedby="showCounter ? 'question-counter' : undefined"
        />
        <AppButton
          v-if="isStreaming"
          type="button"
          variant="danger"
          size="sm"
          @click="store.abortStream()"
        >
          Stop
        </AppButton>
        <AppButton
          v-else
          type="submit"
          variant="primary"
          size="sm"
          :disabled="isLoading || !question.trim()"
        >
          Send
        </AppButton>
      </form>
      <p
        v-if="showCounter"
        id="question-counter"
        :class="['mt-1 text-xs text-right tabular-nums', counterClass]"
        :aria-live="question.length >= MAX_QUESTION_LENGTH ? 'polite' : 'off'"
      >
        {{ question.length }} / {{ MAX_QUESTION_LENGTH }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'
import AppButton from '../components/ui/AppButton.vue'
import AppEmptyState from '../components/ui/AppEmptyState.vue'
import { useChatStore } from '../stores/chatStore'
import { useDocumentStore } from '../stores/documentStore'
import { useSettingsStore } from '../stores/settingsStore'
import { settingsService } from '../services/settingsService'
import { storeToRefs } from 'pinia'
import { formatRelativeTime } from '../utils/dates'

const store = useChatStore()
const docStore = useDocumentStore()
const settingsStore = useSettingsStore()
const { messages, isLoading, isStreaming, error } = storeToRefs(store)
const { documents } = storeToRefs(docStore)

const MAX_QUESTION_LENGTH = 1000
const COUNTER_REVEAL_THRESHOLD = 800
const COUNTER_WARNING_THRESHOLD = 950

const question = ref('')
const messagesContainer = ref<HTMLElement | null>(null)
const showCounter = computed(() => question.value.length >= COUNTER_REVEAL_THRESHOLD)
const counterClass = computed(() => {
  const len = question.value.length
  if (len >= MAX_QUESTION_LENGTH) return 'text-danger-600 font-medium'
  if (len >= COUNTER_WARNING_THRESHOLD) return 'text-warning-700'
  return 'text-surface-400'
})
const showFilters = ref(false)
const selectedDocIds = ref<string[]>([])
const dateFrom = ref('')
const dateTo = ref('')
const selectedLlmModel = ref('')
const llmModelsList = ref<{ id: string; name: string }[]>([])

const llmModelOptions = computed(() => llmModelsList.value.map(m => ({ value: m.id, label: m.name })))

const activeFilterCount = computed(() => {
  let count = 0
  if (selectedDocIds.value.length > 0) count++
  if (dateFrom.value || dateTo.value) count++
  return count
})

onMounted(async () => {
  docStore.fetchDocuments()
  settingsStore.fetch()
  try {
    const res = await settingsService.getAiModels('llm')
    llmModelsList.value = (res.data ?? []).map((m: any) => ({ id: m.id, name: m.name }))
  } catch {
    // ignore
  }
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
  await store.sendMessage(q, filter ?? undefined, selectedLlmModel.value || undefined)
}
</script>

<style scoped>
/* Filter chip: visible focus ring when the hidden checkbox is focused */
.filter-chip:has(input:focus-visible) {
  outline: 2px solid var(--color-brand-500);
  outline-offset: 2px;
}

/* Typing-dots animation */
@keyframes typing-bounce {
  0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
  30% { transform: translateY(-3px); opacity: 1; }
}
.typing-dot {
  animation: typing-bounce 900ms ease-in-out infinite;
}
</style>
