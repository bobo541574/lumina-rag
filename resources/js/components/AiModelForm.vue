<template>
  <form class="bg-white border border-surface-200 rounded-card overflow-hidden" @submit.prevent="onSubmit">
    <div class="px-6 py-4 space-y-4">
      <!-- Basic Info -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label for="model-name" class="block text-sm font-medium text-surface-700 mb-1">Name</label>
          <AppInput id="model-name" v-model="form.name" required />
        </div>
        <div>
          <label for="model-type" class="block text-sm font-medium text-surface-700 mb-1">Type</label>
          <AppSelect id="model-type" v-model="form.type">
            <option value="embedding">Embedding</option>
            <option value="llm">LLM</option>
          </AppSelect>
        </div>
        <div>
          <label for="model-provider" class="block text-sm font-medium text-surface-700 mb-1">Provider</label>
          <AppSelect id="model-provider" v-model="form.provider">
            <option value="openai">OpenAI</option>
            <option value="ollama">Ollama</option>
          </AppSelect>
        </div>
        <div>
          <label for="model-id" class="block text-sm font-medium text-surface-700 mb-1">Model ID</label>
          <AppInput id="model-id" v-model="form.model" required />
        </div>
        <div>
          <label for="model-api-key" class="block text-sm font-medium text-surface-700 mb-1">
            API Key
            <span v-if="form.provider === 'openai'" class="text-danger-600" aria-label="required">*</span>
            <span v-else class="text-surface-400 text-xs font-normal">(optional)</span>
          </label>
          <AppInput
            id="model-api-key"
            type="password"
            v-model="form.api_key"
            :placeholder="apiKeyPlaceholder"
            autocomplete="off"
          />
          <p v-if="mode === 'edit'" class="mt-1 text-xs text-surface-400">
            Leave blank to keep the existing key.
          </p>
        </div>
        <div>
          <label for="model-base-url" class="block text-sm font-medium text-surface-700 mb-1">
            Base URL
            <span v-if="form.provider === 'ollama'" class="text-danger-600" aria-label="required">*</span>
            <span v-else class="text-surface-400 text-xs font-normal">(optional)</span>
          </label>
          <AppInput id="model-base-url" v-model="form.base_url" :placeholder="baseUrlPlaceholder" />
        </div>
        <div>
          <label for="model-collection" class="block text-sm font-medium text-surface-700 mb-1">Collection</label>
          <AppSelect id="model-collection" v-model="form.collection">
            <option value="">Auto-detect</option>
            <option value="ve_384">ve_384</option>
            <option value="ve_768">ve_768</option>
            <option value="ve_1024">ve_1024</option>
            <option value="ve_1536">ve_1536</option>
            <option value="ve_3072">ve_3072</option>
          </AppSelect>
        </div>
        <div>
          <label for="model-timeout" class="block text-sm font-medium text-surface-700 mb-1">Timeout (s)</label>
          <AppInput id="model-timeout" type="number" v-model.number="form.timeout" />
        </div>
      </div>

      <!-- Embedding-specific -->
      <div v-if="form.type === 'embedding'" class="pt-4 border-t border-surface-200">
        <h4 class="text-sm font-semibold text-surface-700 mb-3">Embedding Settings</h4>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label for="model-dims" class="block text-sm font-medium text-surface-700 mb-1">Dimensions</label>
            <AppInput id="model-dims" type="number" v-model.number="form.dimensions" />
          </div>
          <div>
            <label for="model-batch" class="block text-sm font-medium text-surface-700 mb-1">Batch Size</label>
            <AppInput id="model-batch" type="number" v-model.number="form.batch_size" />
          </div>
          <div>
            <label for="model-cache" class="block text-sm font-medium text-surface-700 mb-1">Cache TTL (s)</label>
            <AppInput id="model-cache" type="number" v-model.number="form.cache_ttl" />
          </div>
        </div>
      </div>

      <!-- LLM-specific -->
      <div v-if="form.type === 'llm'" class="pt-4 border-t border-surface-200">
        <h4 class="text-sm font-semibold text-surface-700 mb-3">LLM Settings</h4>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label for="model-temp" class="block text-sm font-medium text-surface-700 mb-1">Temperature</label>
            <AppInput id="model-temp" type="number" v-model.number="form.temperature" step="0.1" min="0" max="2" />
          </div>
          <div>
            <label for="model-max-ctx" class="block text-sm font-medium text-surface-700 mb-1">Max Context Tokens</label>
            <AppInput id="model-max-ctx" type="number" v-model.number="form.max_context_tokens" />
          </div>
          <div>
            <label for="model-max-tokens" class="block text-sm font-medium text-surface-700 mb-1">Max Output Tokens</label>
            <AppInput id="model-max-tokens" type="number" v-model.number="form.max_tokens" />
          </div>
        </div>
      </div>

      <!-- Description (Trix) -->
      <div class="pt-4 border-t border-surface-200">
        <label class="block text-sm font-medium text-surface-700 mb-2">Description</label>
        <div class="trix-wrapper border border-surface-300 rounded-card overflow-hidden focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 transition-colors">
          <trix-editor
            ref="trixRef"
            @trix-change="onTrixChange"
            class="min-h-[150px]"
          ></trix-editor>
        </div>
      </div>

      <!-- Extended Settings -->
      <div class="pt-4 border-t border-surface-200">
        <h4 class="text-sm font-semibold text-surface-700 mb-3">Search &amp; Chunking Settings (JSON)</h4>
        <p class="text-xs text-surface-400 mb-2">Optional overrides for search, chunking, and chat configuration.</p>
        <AppTextarea
          v-model="form.settingsStr"
          rows="6"
          placeholder='{"top_k": 5, "search_mode": "hybrid", "chunk_size": 1000}'
          :aria-invalid="settingsJsonError ? 'true' : undefined"
        />
        <p v-if="settingsJsonError" class="mt-1 text-xs text-danger-600">{{ settingsJsonError }}</p>
      </div>

      <!-- Active / Sort -->
      <div class="flex items-center gap-6 pt-2">
        <AppCheckbox v-model="form.is_active">Active</AppCheckbox>
        <div>
          <label for="model-sort" class="text-sm text-surface-700 mr-2">Sort Order</label>
          <AppInput id="model-sort" type="number" v-model.number="form.sort_order" />
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-surface-200 bg-surface-50 flex-wrap">
      <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
      <div class="flex gap-2 ml-auto">
        <AppButton type="button" variant="ghost" :disabled="submitting" @click="$emit('cancel')">
          Cancel
        </AppButton>
        <AppButton
          type="submit"
          variant="primary"
          :loading="submitting"
          loading-label="Saving…"
        >
          {{ mode === 'edit' ? 'Save changes' : 'Create model' }}
        </AppButton>
      </div>
    </div>
  </form>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import AppInput from './ui/AppInput.vue'
import AppSelect from './ui/AppSelect.vue'
import AppCheckbox from './ui/AppCheckbox.vue'
import AppButton from './ui/AppButton.vue'
import AppTextarea from './ui/AppTextarea.vue'
import type { AiModel } from '../types'

interface FormState {
  name: string
  type: AiModel['type']
  provider: string
  model: string
  api_key: string
  base_url: string
  collection: string
  dimensions: number | null
  batch_size: number | null
  cache_ttl: number | null
  temperature: number | null
  max_context_tokens: number | null
  max_tokens: number | null
  timeout: number
  description: string
  settingsStr: string
  is_active: boolean
  sort_order: number
}

const props = withDefaults(defineProps<{
  mode: 'create' | 'edit'
  initialValues?: AiModel | null
  submitting?: boolean
  error?: string | null
}>(), {
  initialValues: null,
  submitting: false,
  error: null,
})

const emit = defineEmits<{
  submit: [payload: Record<string, unknown>]
  cancel: []
}>()

const trixRef = ref<any>(null)
const settingsJsonError = ref<string | null>(null)

const form = ref<FormState>(buildBlankForm())

function buildBlankForm(): FormState {
  return {
    name: '',
    type: 'embedding',
    provider: 'openai',
    model: '',
    api_key: '',
    base_url: '',
    collection: '',
    dimensions: null,
    batch_size: null,
    cache_ttl: null,
    temperature: null,
    max_context_tokens: null,
    max_tokens: null,
    timeout: 30,
    description: '',
    settingsStr: '',
    is_active: true,
    sort_order: 0,
  }
}

function applyInitialValues(m: AiModel | null | undefined) {
  if (!m) {
    form.value = buildBlankForm()
    loadTrixContent('')
    return
  }
  form.value = {
    name: m.name,
    type: m.type,
    provider: m.provider,
    model: m.model,
    // Never pre-fill the API key on edit; the server keeps the existing value
    // unless the field is replaced explicitly.
    api_key: '',
    base_url: m.base_url || '',
    collection: m.collection || '',
    dimensions: m.dimensions ?? null,
    batch_size: m.batch_size ?? null,
    cache_ttl: m.cache_ttl ?? null,
    temperature: m.temperature ?? null,
    max_context_tokens: m.max_context_tokens ?? null,
    max_tokens: (m.settings?.max_tokens as number | undefined) ?? null,
    timeout: m.timeout ?? 30,
    description: m.description || '',
    settingsStr: m.settings ? JSON.stringify(m.settings, null, 2) : '',
    is_active: m.is_active,
    sort_order: m.sort_order ?? 0,
  }
  loadTrixContent(m.description || '')
}

function loadTrixContent(html: string) {
  nextTick(() => {
    if (trixRef.value?.editor) {
      trixRef.value.editor.loadHTML(html)
    }
  })
}

function onTrixChange(event: Event) {
  const el = event.target as any
  form.value.description = el?.editor?.getDocument().toHTML() || ''
}

const apiKeyPlaceholder = computed(() => {
  if (props.mode === 'edit') return '••••••••  (leave blank to keep existing)'
  return form.value.provider === 'openai' ? 'sk-…' : ''
})

const baseUrlPlaceholder = computed(() =>
  form.value.provider === 'ollama' ? 'http://localhost:11434' : '',
)

watch(() => props.initialValues, (m) => applyInitialValues(m), { immediate: true })

function buildPayload(): Record<string, unknown> | null {
  settingsJsonError.value = null
  const f = form.value
  const payload: Record<string, unknown> = {
    name: f.name,
    type: f.type,
    provider: f.provider,
    model: f.model,
    base_url: f.base_url || null,
    collection: f.collection || null,
    timeout: f.timeout,
    description: f.description || null,
    is_active: f.is_active,
    sort_order: f.sort_order,
  }

  if (f.type === 'embedding') {
    payload.dimensions = f.dimensions || null
    payload.batch_size = f.batch_size || null
    payload.cache_ttl = f.cache_ttl || null
  } else {
    payload.temperature = f.temperature ?? null
    payload.max_context_tokens = f.max_context_tokens || null
  }

  // Only send api_key when creating, or when the user typed a new key while editing.
  if (props.mode === 'create') {
    payload.api_key = f.api_key || null
  } else if (f.api_key.trim() !== '') {
    payload.api_key = f.api_key
  }

  if (f.settingsStr.trim()) {
    try {
      payload.settings = { ...JSON.parse(f.settingsStr) }
    } catch {
      settingsJsonError.value = 'Invalid JSON in settings'
      return null
    }
  } else {
    payload.settings = {}
  }

  if (f.max_tokens !== null) {
    (payload.settings as Record<string, unknown>).max_tokens = f.max_tokens
  } else {
    delete (payload.settings as Record<string, unknown>).max_tokens
  }

  if (Object.keys(payload.settings as Record<string, unknown>).length === 0) {
    payload.settings = null
  }

  return payload
}

function onSubmit() {
  const payload = buildPayload()
  if (payload === null) return
  emit('submit', payload)
}
</script>
