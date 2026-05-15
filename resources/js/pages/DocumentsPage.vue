<template>
  <div>
    <!-- Heading row: title + count + search + page size -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <h2 class="text-lg font-semibold text-surface-900 flex items-center gap-2">
        Documents
        <span v-if="totalCount > 0" class="text-sm font-normal text-surface-500 tabular-nums">
          ({{ totalCount }})
        </span>
      </h2>

      <div v-if="totalCount > 0" class="flex items-center gap-2 flex-1 sm:flex-initial sm:min-w-[20rem] sm:max-w-md">
        <div class="relative flex-1">
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <AppInput
            v-model="searchQuery"
            type="search"
            placeholder="Search by title or filename"
            class="!pl-9"
            aria-label="Search documents"
          />
        </div>
        <AppSelect
          v-model.number="pageSize"
          aria-label="Page size"
          class="w-24"
        >
          <option v-for="n in PAGE_SIZE_OPTIONS" :key="n" :value="n">{{ n }} / page</option>
        </AppSelect>
      </div>
    </div>

    <DocumentUpload @uploaded="onUploaded" />

    <!-- Status tabs -->
    <div
      v-if="totalCount > 0"
      class="flex flex-wrap items-center gap-1 mb-3 border-b border-surface-200"
      role="tablist"
      aria-label="Filter documents by status"
    >
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        role="tab"
        :aria-selected="statusFilter === tab.key"
        :class="[
          'px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded-t',
          statusFilter === tab.key
            ? 'border-brand-600 text-brand-700'
            : 'border-transparent text-surface-500 hover:text-surface-800 hover:border-surface-300',
        ]"
        @click="statusFilter = tab.key"
      >
        {{ tab.label }}
        <span class="ml-1 text-xs text-surface-400 tabular-nums">{{ tab.count }}</span>
      </button>
    </div>

    <!-- Bulk action bar -->
    <div
      v-if="selectedIds.size > 0"
      class="mb-3 flex items-center justify-between gap-3 px-4 py-2 bg-brand-50 border border-brand-200 rounded-card"
      role="region"
      aria-label="Bulk selection"
    >
      <span class="text-sm text-brand-800">
        <span class="font-medium tabular-nums">{{ selectedIds.size }}</span>
        selected
      </span>
      <div class="flex items-center gap-2">
        <AppButton variant="ghost" size="sm" @click="clearSelection">Clear</AppButton>
        <AppButton variant="danger" size="sm" @click="askBulkDelete">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" />
          </svg>
          Delete selected
        </AppButton>
      </div>
    </div>

    <DocumentList
      :documents="pagedDocuments"
      :sort-key="sortKey"
      :sort-dir="sortDir"
      :selectable="totalCount > 0"
      :selected-ids="selectedIds"
      :loading="initialLoading"
      :empty-title="totalCount === 0 ? 'No documents uploaded yet' : 'No matches'"
      :empty-description="totalCount === 0 ? 'Upload a PDF, DOCX, or TXT file above to get started.' : 'Try clearing the search or status filter.'"
      @retry="handleRetry"
      @delete="askDelete"
      @view="handleView"
      @sort="onSort"
      @toggle-select="toggleSelect"
      @toggle-select-all="togglePageSelection"
    />

    <!-- Pagination footer -->
    <div
      v-if="totalFiltered > pageSize"
      class="mt-3 flex items-center justify-between gap-3 flex-wrap text-sm text-surface-600"
    >
      <span class="tabular-nums">
        Showing
        <span class="font-medium text-surface-800">{{ rangeStart }}</span>
        –<span class="font-medium text-surface-800">{{ rangeEnd }}</span>
        of <span class="font-medium text-surface-800">{{ totalFiltered }}</span>
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

    <DocumentDetail :doc="selectedDoc" @close="selectedDoc = null" />

    <AppConfirm
      v-model="confirmOpen"
      :title="confirmTitle"
      :message="confirmMessage"
      :confirm-label="confirmAction === 'bulk' ? `Delete ${selectedIds.size}` : 'Delete'"
      confirm-variant="danger"
      confirm-loading-label="Deleting…"
      :loading="isDeleting"
      @confirm="performDelete"
      @cancel="onConfirmCancel"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useDocumentStore } from '../stores/documentStore'
import { storeToRefs } from 'pinia'
import { useToast } from '../composables/useToast'
import type { Document } from '../types'
import DocumentUpload from '../components/DocumentUpload.vue'
import DocumentList, { type SortKey, type SortDir } from '../components/DocumentList.vue'
import DocumentDetail from '../components/DocumentDetail.vue'
import AppConfirm from '../components/ui/AppConfirm.vue'
import AppButton from '../components/ui/AppButton.vue'
import AppInput from '../components/ui/AppInput.vue'
import AppSelect from '../components/ui/AppSelect.vue'

type StatusFilter = 'all' | Document['status']

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100]

const store = useDocumentStore()
const toast = useToast()
const { documents } = storeToRefs(store)
const selectedDoc = ref<Document | null>(null)

// --- Filter, search, sort, pagination state ---
const statusFilter = ref<StatusFilter>('all')
const searchQuery = ref('')
const sortKey = ref<SortKey>('created_at')
const sortDir = ref<SortDir>('desc')
const currentPage = ref(1)
const pageSize = ref(25)

// --- Selection state (preserved across pages) ---
const selectedIds = ref<Set<string>>(new Set())

// --- Derived data ---
const totalCount = computed(() => documents.value.length)

const counts = computed(() => {
  const map: Record<Document['status'], number> = { pending: 0, processing: 0, completed: 0, failed: 0 }
  for (const doc of documents.value) {
    if (doc.status in map) map[doc.status]++
  }
  return map
})

const tabs = computed(() => [
  { key: 'all'        as StatusFilter, label: 'All',        count: totalCount.value      },
  { key: 'pending'    as StatusFilter, label: 'Pending',    count: counts.value.pending    },
  { key: 'processing' as StatusFilter, label: 'Processing', count: counts.value.processing },
  { key: 'completed'  as StatusFilter, label: 'Completed',  count: counts.value.completed  },
  { key: 'failed'     as StatusFilter, label: 'Failed',     count: counts.value.failed     },
])

const filteredDocuments = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  return documents.value.filter((d) => {
    if (statusFilter.value !== 'all' && d.status !== statusFilter.value) return false
    if (!q) return true
    return (
      d.title?.toLowerCase().includes(q) ||
      d.original_filename?.toLowerCase().includes(q)
    )
  })
})

const sortedDocuments = computed(() => {
  const docs = [...filteredDocuments.value]
  const dir = sortDir.value === 'asc' ? 1 : -1
  const key = sortKey.value
  docs.sort((a, b) => {
    const av = (a as any)[key]
    const bv = (b as any)[key]
    if (av == null && bv == null) return 0
    if (av == null) return 1
    if (bv == null) return -1
    if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir
    if (key === 'created_at') return (new Date(av).getTime() - new Date(bv).getTime()) * dir
    return String(av).localeCompare(String(bv)) * dir
  })
  return docs
})

const totalFiltered = computed(() => sortedDocuments.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalFiltered.value / pageSize.value)))

const pagedDocuments = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return sortedDocuments.value.slice(start, start + pageSize.value)
})

const rangeStart = computed(() =>
  totalFiltered.value === 0 ? 0 : (currentPage.value - 1) * pageSize.value + 1,
)
const rangeEnd = computed(() =>
  Math.min(currentPage.value * pageSize.value, totalFiltered.value),
)

// Reset to page 1 whenever filters/search/sort/page-size change
watch([statusFilter, searchQuery, sortKey, sortDir, pageSize], () => {
  currentPage.value = 1
})

// Clamp current page if list shrinks
watch(totalPages, (pages) => {
  if (currentPage.value > pages) currentPage.value = pages
})

// --- Sort handler ---
function onSort(key: SortKey) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = key === 'title' || key === 'status' ? 'asc' : 'desc'
  }
}

// --- Selection handlers ---
function toggleSelect(id: string) {
  const next = new Set(selectedIds.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selectedIds.value = next
}

function togglePageSelection() {
  const pageIds = pagedDocuments.value.map((d) => d.id)
  const allSelected = pageIds.every((id) => selectedIds.value.has(id))
  const next = new Set(selectedIds.value)
  if (allSelected) {
    pageIds.forEach((id) => next.delete(id))
  } else {
    pageIds.forEach((id) => next.add(id))
  }
  selectedIds.value = next
}

function clearSelection() {
  selectedIds.value = new Set()
}

function onUploaded() {
  store.fetchDocuments()
  // No selection invalidation needed — uploaded ID isn't selected yet.
}

const initialLoading = ref(true)

onMounted(async () => {
  try {
    await store.fetchDocuments()
  } finally {
    initialLoading.value = false
  }
})

function handleView(doc: Document) {
  selectedDoc.value = doc
}

async function handleRetry(id: string) {
  try {
    await store.retryDocument(id)
    toast.info('Retrying document processing')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to retry document')
  }
}

// --- Single + bulk delete via AppConfirm ---
type ConfirmAction = 'single' | 'bulk'

const confirmOpen = ref(false)
const confirmAction = ref<ConfirmAction>('single')
const pendingDeleteId = ref<string | null>(null)
const isDeleting = ref(false)

const pendingDoc = computed(() =>
  pendingDeleteId.value ? documents.value.find((d) => d.id === pendingDeleteId.value) : null,
)
const confirmTitle = computed(() =>
  confirmAction.value === 'bulk' ? `Delete ${selectedIds.value.size} documents?` : 'Delete document?',
)
const confirmMessage = computed(() => {
  if (confirmAction.value === 'bulk') {
    return `${selectedIds.value.size} documents and all their embeddings will be permanently removed. This cannot be undone.`
  }
  const title = pendingDoc.value?.title ?? 'this document'
  return `"${title}" and its embeddings will be permanently removed. This cannot be undone.`
})

function askDelete(id: string) {
  confirmAction.value = 'single'
  pendingDeleteId.value = id
  confirmOpen.value = true
}

function askBulkDelete() {
  if (selectedIds.value.size === 0) return
  confirmAction.value = 'bulk'
  confirmOpen.value = true
}

function onConfirmCancel() {
  pendingDeleteId.value = null
}

async function performDelete() {
  isDeleting.value = true
  try {
    if (confirmAction.value === 'single') {
      if (!pendingDeleteId.value) return
      await store.deleteDocument(pendingDeleteId.value)
      toast.success('Document deleted')
      // remove from selection if present
      const next = new Set(selectedIds.value)
      next.delete(pendingDeleteId.value)
      selectedIds.value = next
      pendingDeleteId.value = null
    } else {
      const ids = Array.from(selectedIds.value)
      const results = await Promise.allSettled(ids.map((id) => store.deleteDocument(id)))
      const failed = results.filter((r) => r.status === 'rejected').length
      const succeeded = ids.length - failed
      if (succeeded > 0) toast.success(`Deleted ${succeeded} document${succeeded === 1 ? '' : 's'}`)
      if (failed > 0) toast.error(`Failed to delete ${failed} document${failed === 1 ? '' : 's'}`)
      // Drop successfully deleted ids from selection
      const next = new Set(selectedIds.value)
      results.forEach((r, i) => {
        if (r.status === 'fulfilled') next.delete(ids[i])
      })
      selectedIds.value = next
    }
    confirmOpen.value = false
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Delete failed')
  } finally {
    isDeleting.value = false
  }
}
</script>
