<template>
  <div>
    <div class="mb-4 flex items-center gap-3 flex-wrap">
      <AppButton variant="ghost" size="sm" @click="goBack">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        AI Models
      </AppButton>
      <span class="text-surface-300" aria-hidden="true">/</span>
      <h2 class="text-lg font-semibold text-surface-900">
        {{ mode === 'edit' ? 'Edit Model' : 'Add Model' }}
      </h2>
    </div>

    <!-- Loading the model on edit -->
    <div
      v-if="mode === 'edit' && isFetching"
      class="bg-white border border-surface-200 rounded-card p-6 space-y-4"
      aria-busy="true"
      aria-label="Loading model"
    >
      <div class="grid grid-cols-2 gap-4">
        <div v-for="i in 4" :key="i" class="space-y-2">
          <div class="h-3 w-20 bg-surface-200 rounded animate-pulse" />
          <div class="h-9 bg-surface-100 rounded-lg animate-pulse" />
        </div>
      </div>
      <div class="h-32 bg-surface-100 rounded-card animate-pulse" />
    </div>

    <div
      v-else-if="mode === 'edit' && fetchError"
      class="bg-danger-50 border border-danger-200 rounded-card p-4 text-sm text-danger-700"
      role="alert"
    >
      {{ fetchError }}
    </div>

    <AiModelForm
      v-else
      :mode="mode"
      :initial-values="initialModel"
      :submitting="isSaving"
      :error="saveError"
      @submit="handleSubmit"
      @cancel="goBack"
    />
  </div>
</template>

<script setup lang="ts">
/**
 * AI Model Manager Page
 *
 * Create/edit form for AI model registry entries. Determines mode (create vs
 * edit) from the route params. On edit mode, fetches the existing model data
 * and passes it to the AiModelForm component.
 *
 * @prop {void} - Route-driven (route.params.id determines mode)
 * @emits {void} - Navigation is handled via router
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AiModelForm from '../components/AiModelForm.vue'
import AppButton from '../components/ui/AppButton.vue'
import { aiModelService } from '../services/aiModelService'
import { useToast } from '../composables/useToast'
import type { AiModel } from '../types'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const mode = computed<'create' | 'edit'>(() =>
  typeof route.params.id === 'string' && route.params.id.length > 0 ? 'edit' : 'create',
)

const initialModel = ref<AiModel | null>(null)
const isFetching = ref(false)
const fetchError = ref<string | null>(null)
const isSaving = ref(false)
const saveError = ref<string | null>(null)

onMounted(async () => {
  if (mode.value !== 'edit') return
  const id = route.params.id as string
  isFetching.value = true
  try {
    const res = await aiModelService.get(id)
    initialModel.value = res.data ?? null
    if (!initialModel.value) {
      fetchError.value = 'Model not found.'
    }
  } catch (e: any) {
    fetchError.value = e?.response?.data?.message ?? 'Failed to load model'
  } finally {
    isFetching.value = false
  }
})

/**
 * Handle form submission (create or update)
 *
 * @param {Record<string, unknown>} payload Form values. Example: { name: "GPT-4o", type: "llm", provider: "openai", ... }
 */
async function handleSubmit(payload: Record<string, unknown>) {
  isSaving.value = true
  saveError.value = null
  try {
    if (mode.value === 'edit') {
      const id = route.params.id as string
      await aiModelService.update(id, payload as Partial<AiModel>)
      toast.success('Model updated')
    } else {
      await aiModelService.create(payload as Partial<AiModel>)
      toast.success('Model created')
    }
    router.push({ name: 'ai-models' })
  } catch (e: any) {
    saveError.value = e?.response?.data?.message ?? 'Failed to save model'
  } finally {
    isSaving.value = false
  }
}

/**
 * Navigate back to the AI models list
 */
function goBack() {
  router.push({ name: 'ai-models' })
}
</script>
