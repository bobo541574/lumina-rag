<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <h2 class="text-lg font-semibold text-surface-900">AI Models</h2>
      <AppButton variant="primary" size="md" @click="goCreate">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add Model
      </AppButton>
    </div>

    <AiModelList
      :models="models"
      :loading="initialLoading"
      @edit="goEdit"
      @delete="askDelete"
    />

    <div
      v-if="totalPages > 1"
      class="mt-3 flex items-center justify-between gap-3 flex-wrap text-sm text-surface-600"
    >
      <span class="tabular-nums">
        Showing
        <span class="font-medium text-surface-800">{{ rangeStart }}</span>
        –<span class="font-medium text-surface-800">{{ rangeEnd }}</span>
        of <span class="font-medium text-surface-800">{{ totalCount }}</span>
      </span>
      <div class="flex items-center gap-2">
        <AppButton
          variant="ghost"
          size="sm"
          :disabled="currentPage <= 1"
          aria-label="Previous page"
          @click="currentPage = Math.max(1, currentPage - 1)"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Prev
        </AppButton>
        <span class="text-surface-500 tabular-nums">
          Page <span class="font-medium text-surface-800">{{ currentPage }}</span>
          of <span class="font-medium text-surface-800">{{ totalPages }}</span>
        </span>
        <AppButton
          variant="ghost"
          size="sm"
          :disabled="currentPage >= totalPages"
          aria-label="Next page"
          @click="currentPage = Math.min(totalPages, currentPage + 1)"
        >
          Next
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </AppButton>
      </div>
    </div>

    <AppConfirm
      v-model="confirmOpen"
      title="Delete model?"
      :message="confirmMessage"
      confirm-label="Delete"
      confirm-variant="danger"
      confirm-loading-label="Deleting…"
      :loading="isDeleting"
      @confirm="performDelete"
      @cancel="pendingDeleteId = null"
    />
  </div>
</template>

<script setup lang="ts">
/**
 * AI Models Page
 *
 * Lists all AI models grouped by type (embedding/LLM) with pagination.
 * Provides create, edit, and delete actions. Deletion is confirmed via
 * a modal dialog before proceeding.
 *
 * @emits {void} - This page is route-driven; no custom emits
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import AiModelList from '../components/AiModelList.vue'
import AppButton from '../components/ui/AppButton.vue'
import AppConfirm from '../components/ui/AppConfirm.vue'
import { aiModelService } from '../services/aiModelService'
import { useToast } from '../composables/useToast'
import type { AiModel, PaginationMeta } from '../types'

const router = useRouter()
const toast = useToast()

const models = ref<AiModel[]>([])
const meta = ref<PaginationMeta | null>(null)
const initialLoading = ref(true)
const currentPage = ref(1)

const totalCount = computed(() => meta.value?.total ?? 0)
const totalPages = computed(() => meta.value?.last_page ?? 1)
const rangeStart = computed(() =>
  totalCount.value === 0 ? 0 : ((meta.value?.current_page ?? 1) - 1) * (meta.value?.per_page ?? 20) + 1,
)
const rangeEnd = computed(() =>
  Math.min((meta.value?.current_page ?? 1) * (meta.value?.per_page ?? 20), totalCount.value),
)

const confirmOpen = ref(false)
const pendingDeleteId = ref<string | null>(null)
const isDeleting = ref(false)

const pendingModel = computed(() =>
  pendingDeleteId.value ? models.value.find((m) => m.id === pendingDeleteId.value) : null,
)
const confirmMessage = computed(() => {
  const name = pendingModel.value?.name ?? 'this model'
  return `"${name}" will be removed from the model registry. Documents already embedded with it will keep their embeddings.`
})

/**
 * Fetch all AI models for the current page
 */
async function fetchModels() {
  try {
    const res = await aiModelService.getAll(undefined, currentPage.value, 20)
    models.value = res.data ?? []
    meta.value = res.meta ?? null
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to load models')
  }
}

watch(currentPage, fetchModels)

onMounted(async () => {
  try {
    await fetchModels()
  } finally {
    initialLoading.value = false
  }
})

/**
 * Navigate to the create model page
 */
function goCreate() {
  router.push({ name: 'ai-model-create' })
}

/**
 * Navigate to the edit model page for a given model ID
 *
 * @param {string} id Model ULID. Example: "01J..."
 */
function goEdit(id: string) {
  router.push({ name: 'ai-model-edit', params: { id } })
}

/**
 * Open the delete confirmation dialog
 *
 * @param {string} id Model ULID to delete. Example: "01J..."
 */
function askDelete(id: string) {
  pendingDeleteId.value = id
  confirmOpen.value = true
}

/**
 * Execute the pending model deletion
 */
async function performDelete() {
  if (!pendingDeleteId.value) return
  isDeleting.value = true
  try {
    await aiModelService.delete(pendingDeleteId.value)
    toast.success('Model deleted')
    confirmOpen.value = false
    pendingDeleteId.value = null
    currentPage.value = 1
    await fetchModels()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to delete model')
  } finally {
    isDeleting.value = false
  }
}
</script>
