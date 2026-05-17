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

    <!-- Template download -->
    <div class="mb-4">
      <button
        type="button"
        class="flex items-center gap-1.5 text-sm text-surface-500 hover:text-surface-800 transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded"
        @click="templateOpen = !templateOpen"
        aria-expanded="templateOpen"
      >
        <svg class="w-4 h-4 transition-transform" :class="templateOpen ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
        Download Report Templates
      </button>
      <div v-if="templateOpen" class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
        <div
          v-for="t in templates"
          :key="t.label"
          class="border border-surface-200 rounded-card bg-white p-3"
        >
          <p class="text-sm font-medium text-surface-800 mb-2">{{ t.label }}</p>
          <div class="flex flex-wrap items-center gap-1.5">
            <a
              v-for="f in t.files"
              :key="f.ext"
              :href="f.url"
              download
              class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded transition-colors"
              :class="formatClass(f.ext)"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              {{ formatLabel(f.ext) }}
            </a>
          </div>
        </div>
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
      :documents="documents"
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
/**
 * Documents Page
 *
 * Full document management view. Supports uploading, searching, filtering by
 * status, sorting, pagination, single/bulk selection, single/bulk deletion,
 * and viewing/editing document details in a modal. Also provides template
 * downloads for common report formats.
 *
 * @prop {void} - This page is route-driven; no custom props
 * @emits {void} - All side-effects are store actions or service calls
 */
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
const { documents, meta } = storeToRefs(store)
const selectedDoc = ref<Document | null>(null)

/**
 * Get a human-readable file format label
 *
 * @param {string} ext File extension. Example: "md"
 * @returns {string} Uppercase label. Example: "MD"
 */
function formatLabel(ext: string): string {
  const map: Record<string, string> = { md: 'MD', txt: 'TXT', docx: 'DOCX', csv: 'CSV' }
  return map[ext] ?? ext.toUpperCase()
}

/**
 * Get Tailwind classes for a file format badge
 *
 * @param {string} ext File extension. Example: "md"
 * @returns {string} Tailwind class string. Example: "bg-blue-50 text-blue-700 hover:bg-blue-100"
 */
function formatClass(ext: string): string {
  const map: Record<string, string> = {
    md: 'bg-blue-50 text-blue-700 hover:bg-blue-100',
    txt: 'bg-gray-50 text-gray-700 hover:bg-gray-100',
    docx: 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
    csv: 'bg-amber-50 text-amber-700 hover:bg-amber-100',
  }
  return map[ext] ?? 'bg-surface-50 text-surface-700 hover:bg-surface-100'
}

// --- Template download ---
const templateOpen = ref(false)
const TEMPLATE_BASE = '/templates'
const TEMPLATE_NAMES = [
  'software-developer-report',
  'project-coordinator-report',
  'customer-service-report',
  'finance-report',
  'general-report',
] as const
const FORMAT_EXTS = ['md', 'txt', 'docx', 'csv'] as const
const FORMAT_LABELS: Record<string, string> = {
  md: 'Markdown', txt: 'Plain Text', docx: 'Word', csv: 'CSV',
}
const TEMPLATE_LABELS: Record<string, string> = {
  'software-developer-report': 'Software Developer',
  'project-coordinator-report': 'Project Coordinator',
  'customer-service-report': 'Customer Service',
  'finance-report': 'Finance',
  'general-report': 'General',
}

const templates = TEMPLATE_NAMES.map(name => ({
  label: TEMPLATE_LABELS[name],
  files: FORMAT_EXTS.map(ext => ({ ext, url: `${TEMPLATE_BASE}/${name}.${ext}` })),
}))

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
const totalCount = computed(() => meta.value?.total ?? 0)

const counts = computed(() => {
  const map: Record<Document['status'], number> = { pending: 0, processing: 0, completed: 0, failed: 0 }
  for (const doc of documents.value) {
    if (doc.status in map) map[doc.status]++
  }
  return map
})

const tabs = computed(() => [
  { key: 'all'        as StatusFilter, label: 'All',        count: totalCount.value },
  { key: 'pending'    as StatusFilter, label: 'Pending',    count: counts.value.pending    },
  { key: 'processing' as StatusFilter, label: 'Processing', count: counts.value.processing },
  { key: 'completed'  as StatusFilter, label: 'Completed',  count: counts.value.completed  },
  { key: 'failed'     as StatusFilter, label: 'Failed',     count: counts.value.failed     },
])

const totalPages = computed(() => meta.value?.last_page ?? 1)

const rangeStart = computed(() =>
  totalCount.value === 0 ? 0 : ((meta.value?.current_page ?? 1) - 1) * (meta.value?.per_page ?? 25) + 1,
)
const rangeEnd = computed(() =>
  Math.min((meta.value?.current_page ?? 1) * (meta.value?.per_page ?? 25), totalCount.value),
)

// --- Server fetch helper ---
let searchTimer: ReturnType<typeof setTimeout> | null = null

/**
 * Fetch documents from the server using current filter, search, sort, and pagination state
 */
function fetchPage() {
  store.fetchDocuments({
    status: statusFilter.value === 'all' ? undefined : statusFilter.value,
    page: currentPage.value,
    per_page: pageSize.value,
    search: searchQuery.value.trim() || undefined,
    sort_key: sortKey.value,
    sort_dir: sortDir.value,
  })
}

// Watch filters and re-fetch
watch([statusFilter, sortKey, sortDir, pageSize], () => {
  currentPage.value = 1
  fetchPage()
})

watch(currentPage, () => {
  fetchPage()
})

watch(searchQuery, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    currentPage.value = 1
    fetchPage()
  }, 300)
})

/**
 * Handle sort header click — toggles direction if same key, otherwise sets new key
 *
 * @param {SortKey} key Column sort key. Example: "title"
 */
function onSort(key: SortKey) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = key === 'title' || key === 'status' ? 'asc' : 'desc'
  }
}

/**
 * Toggle selection of a single document
 *
 * @param {string} id Document ULID. Example: "01J..."
 */
function toggleSelect(id: string) {
  const next = new Set(selectedIds.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selectedIds.value = next
}

/**
 * Toggle selection of all documents on the current page
 */
function togglePageSelection() {
  const pageIds = documents.value.map((d) => d.id)
  const allSelected = pageIds.every((id) => selectedIds.value.has(id))
  const next = new Set(selectedIds.value)
  if (allSelected) {
    pageIds.forEach((id) => next.delete(id))
  } else {
    pageIds.forEach((id) => next.add(id))
  }
  selectedIds.value = next
}

/**
 * Clear all document selections
 */
function clearSelection() {
  selectedIds.value = new Set()
}

/**
 * Called after a successful upload — re-fetches the current page
 */
function onUploaded() {
  fetchPage()
}

const initialLoading = ref(true)

onMounted(async () => {
  try {
    await fetchPage()
  } finally {
    initialLoading.value = false
  }
})

/**
 * Open the document detail modal
 *
 * @param {Document} doc The document to view. Example: { id: "01J...", title: "...", ... }
 */
function handleView(doc: Document) {
  selectedDoc.value = doc
}

/**
 * Retry processing for a failed document
 *
 * @param {string} id Document ULID. Example: "01J..."
 */
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

/**
 * Open delete confirmation for a single document
 *
 * @param {string} id Document ULID. Example: "01J..."
 */
function askDelete(id: string) {
  confirmAction.value = 'single'
  pendingDeleteId.value = id
  confirmOpen.value = true
}

/**
 * Open bulk delete confirmation
 */
function askBulkDelete() {
  if (selectedIds.value.size === 0) return
  confirmAction.value = 'bulk'
  confirmOpen.value = true
}

/**
 * Reset pending delete ID on cancel
 */
function onConfirmCancel() {
  pendingDeleteId.value = null
}

/**
 * Execute the pending delete (single or bulk)
 */
async function performDelete() {
  isDeleting.value = true
  try {
    if (confirmAction.value === 'single') {
      if (!pendingDeleteId.value) return
      await store.deleteDocument(pendingDeleteId.value)
      toast.success('Document deleted')
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
      const next = new Set(selectedIds.value)
      results.forEach((r, i) => {
        if (r.status === 'fulfilled') next.delete(ids[i])
      })
      selectedIds.value = next
    }
    confirmOpen.value = false
    fetchPage()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Delete failed')
  } finally {
    isDeleting.value = false
  }
}
</script>
