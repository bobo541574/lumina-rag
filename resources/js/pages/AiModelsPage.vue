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
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AiModelList from '../components/AiModelList.vue'
import AppButton from '../components/ui/AppButton.vue'
import AppConfirm from '../components/ui/AppConfirm.vue'
import { aiModelService } from '../services/aiModelService'
import { useToast } from '../composables/useToast'
import type { AiModel } from '../types'

const router = useRouter()
const toast = useToast()

const models = ref<AiModel[]>([])
const initialLoading = ref(true)

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

async function fetchModels() {
  try {
    const res = await aiModelService.getAll()
    models.value = res.data ?? []
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to load models')
  }
}

onMounted(async () => {
  try {
    await fetchModels()
  } finally {
    initialLoading.value = false
  }
})

function goCreate() {
  router.push({ name: 'ai-model-create' })
}

function goEdit(id: string) {
  router.push({ name: 'ai-model-edit', params: { id } })
}

function askDelete(id: string) {
  pendingDeleteId.value = id
  confirmOpen.value = true
}

async function performDelete() {
  if (!pendingDeleteId.value) return
  isDeleting.value = true
  try {
    await aiModelService.delete(pendingDeleteId.value)
    toast.success('Model deleted')
    confirmOpen.value = false
    pendingDeleteId.value = null
    await fetchModels()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to delete model')
  } finally {
    isDeleting.value = false
  }
}
</script>
