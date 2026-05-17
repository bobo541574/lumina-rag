<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <h2 class="text-lg font-semibold text-surface-900">Term Aliases</h2>
      <AppButton variant="primary" size="md" @click="showForm = true" v-if="!showForm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add Alias
      </AppButton>
    </div>

    <div class="bg-white border border-surface-200 rounded-card overflow-hidden">
      <!-- Creation form -->
      <form v-if="showForm" class="p-4 border-b border-surface-200 bg-brand-50/30" @submit.prevent="handleCreate">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <div>
            <label class="block text-xs font-medium text-surface-600 mb-1">Alias</label>
            <input v-model="form.alias" type="text" required
                   class="w-full border border-surface-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                   placeholder="e.g. quarterly">
          </div>
          <div>
            <label class="block text-xs font-medium text-surface-600 mb-1">Canonical</label>
            <input v-model="form.canonical" type="text" required
                   class="w-full border border-surface-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                   placeholder="e.g. quarterly report">
          </div>
          <div>
            <label class="block text-xs font-medium text-surface-600 mb-1">Type</label>
            <select v-model="form.type" required
                    class="w-full border border-surface-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
              <option value="project">Project</option>
              <option value="technical">Technical</option>
              <option value="general">General</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-surface-600 mb-1">Description</label>
            <input v-model="form.description" type="text"
                   class="w-full border border-surface-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                   placeholder="Optional">
          </div>
          <div class="flex items-end gap-2">
            <AppButton type="submit" variant="primary" size="sm" :loading="saving">Create</AppButton>
            <AppButton variant="ghost" size="sm" @click="resetForm">Cancel</AppButton>
          </div>
        </div>
      </form>

      <!-- Table -->
      <div v-if="initialLoading" class="p-4 space-y-3">
        <div v-for="i in 3" :key="i" class="flex gap-4">
          <div class="h-4 w-24 bg-surface-200 rounded animate-pulse" />
          <div class="h-4 w-32 bg-surface-200 rounded animate-pulse" />
          <div class="h-4 w-16 bg-surface-200 rounded animate-pulse" />
        </div>
      </div>

      <AppEmptyState
        v-else-if="aliases.length === 0"
        icon="info"
        title="No term aliases configured"
        description="Aliases let users search with alternative names (e.g. 'quarterly' for 'quarterly report')."
      />

      <table v-else class="w-full text-sm">
        <thead class="bg-surface-50 border-b border-surface-200">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium text-surface-600">Alias</th>
            <th class="text-left px-4 py-2.5 font-medium text-surface-600">Canonical</th>
            <th class="text-left px-4 py-2.5 font-medium text-surface-600">Type</th>
            <th class="text-left px-4 py-2.5 font-medium text-surface-600 hidden sm:table-cell">Description</th>
            <th class="text-center px-4 py-2.5 font-medium text-surface-600 w-16">Active</th>
            <th class="text-right px-4 py-2.5 font-medium text-surface-600 w-24">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
          <!-- Edit row -->
          <tr v-if="editingId" class="bg-brand-50/30">
            <td colspan="6" class="px-4 py-2">
              <form class="flex flex-wrap items-end gap-2" @submit.prevent="handleUpdate">
                <input v-model="editForm.alias" type="text" required
                       class="w-36 border border-surface-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <input v-model="editForm.canonical" type="text" required
                       class="w-40 border border-surface-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <select v-model="editForm.type" required
                        class="w-28 border border-surface-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                  <option value="project">Project</option>
                  <option value="technical">Technical</option>
                  <option value="general">General</option>
                </select>
                <input v-model="editForm.description" type="text"
                       class="w-40 border border-surface-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                       placeholder="Description">
                <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                  <input v-model="editForm.is_active" type="checkbox" class="rounded border-surface-300">
                  Active
                </label>
                <AppButton type="submit" variant="primary" size="sm" :loading="saving">Save</AppButton>
                <AppButton variant="ghost" size="sm" @click="editingId = null">Cancel</AppButton>
              </form>
            </td>
          </tr>

          <tr v-for="a in aliases" :key="a.id" class="hover:bg-surface-50 transition-colors">
            <td class="px-4 py-2.5 font-medium text-surface-900">{{ a.alias }}</td>
            <td class="px-4 py-2.5 text-surface-700">{{ a.canonical }}</td>
            <td class="px-4 py-2.5">
              <AppBadge :variant="typeBadge[a.type] ?? 'neutral'" size="xs">
                {{ a.type }}
              </AppBadge>
            </td>
            <td class="px-4 py-2.5 text-surface-500 hidden sm:table-cell max-w-[200px] truncate">
              {{ a.description || '—' }}
            </td>
            <td class="px-4 py-2.5 text-center">
              <span :class="a.is_active ? 'text-green-600' : 'text-surface-300'">
                {{ a.is_active ? 'Yes' : 'No' }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right">
              <div class="flex items-center justify-end gap-1">
                <AppButton variant="ghost" size="sm" aria-label="Edit" @click="startEdit(a)">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </AppButton>
                <AppButton variant="danger" size="sm" aria-label="Delete" @click="askDelete(a)">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </AppButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div
        v-if="totalPages > 1"
        class="px-4 py-3 border-t border-surface-200 flex items-center justify-between gap-3 flex-wrap text-sm text-surface-600"
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
    </div>

    <AppConfirm
      v-model="confirmOpen"
      title="Delete alias?"
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
 * Term Aliases Page
 *
 * Full CRUD management for term alias mappings. Displays aliases in a
 * paginated table with inline edit row and a top-of-page create form.
 * Supports create, update, and delete with confirmation dialog.
 * Resets to page 1 after mutations.
 *
 * @prop {void} - This page is route-driven, no props
 * @emits {void} - All side-effects are store/service calls
 */
import { ref, computed, onMounted, watch } from 'vue'
import AppButton from '../components/ui/AppButton.vue'
import AppBadge from '../components/ui/AppBadge.vue'
import AppConfirm from '../components/ui/AppConfirm.vue'
import AppEmptyState from '../components/ui/AppEmptyState.vue'
import { termAliasService } from '../services/termAliasService'
import { useToast } from '../composables/useToast'
import type { PaginationMeta, TermAlias } from '../types'

const toast = useToast()

const aliases = ref<TermAlias[]>([])
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

// Create form
const showForm = ref(false)
const saving = ref(false)
const form = ref({ alias: '', canonical: '', type: 'general', description: '' })

// Edit
const editingId = ref<string | null>(null)
const editForm = ref({ alias: '', canonical: '', type: 'general', description: '', is_active: true })

// Delete
const confirmOpen = ref(false)
const pendingDeleteId = ref<string | null>(null)
const isDeleting = ref(false)

const typeBadge: Record<string, string> = { project: 'info', technical: 'warning', general: 'neutral' }

const pendingAlias = computed(() =>
  pendingDeleteId.value ? aliases.value.find((a) => a.id === pendingDeleteId.value) : null,
)
const confirmMessage = computed(() => {
  const name = pendingAlias.value?.alias ?? 'this alias'
  return `"${name}" will be removed. Existing documents are not affected.`
})

/**
 * Fetch all aliases for the current page
 */
async function fetchAliases() {
  try {
    const res = await termAliasService.getAll(undefined, currentPage.value, 20)
    aliases.value = res.data ?? []
    meta.value = res.meta ?? null
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to load aliases')
  }
}

watch(currentPage, fetchAliases)

onMounted(async () => {
  try {
    await fetchAliases()
  } finally {
    initialLoading.value = false
  }
})

/**
 * Reset the create form and hide it
 */
function resetForm() {
  showForm.value = false
  form.value = { alias: '', canonical: '', type: 'general', description: '' }
}

/**
 * Handle create form submission
 */
async function handleCreate() {
  saving.value = true
  try {
    await termAliasService.create({ ...form.value })
    toast.success('Alias created')
    resetForm()
    currentPage.value = 1
    await fetchAliases()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to create alias')
  } finally {
    saving.value = false
  }
}

/**
 * Start inline editing for an alias
 *
 * @param {TermAlias} a The alias to edit. Example: { id: "01J...", alias: "quarterly", ... }
 */
function startEdit(a: TermAlias) {
  editingId.value = a.id
  editForm.value = {
    alias: a.alias,
    canonical: a.canonical,
    type: a.type,
    description: a.description ?? '',
    is_active: a.is_active,
  }
}

/**
 * Handle update form submission
 */
async function handleUpdate() {
  if (!editingId.value) return
  saving.value = true
  try {
    await termAliasService.update(editingId.value, { ...editForm.value })
    toast.success('Alias updated')
    editingId.value = null
    currentPage.value = 1
    await fetchAliases()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to update alias')
  } finally {
    saving.value = false
  }
}

/**
 * Open delete confirmation dialog
 *
 * @param {TermAlias} a The alias to delete. Example: { id: "01J...", alias: "quarterly", ... }
 */
function askDelete(a: TermAlias) {
  pendingDeleteId.value = a.id
  confirmOpen.value = true
}

/**
 * Execute the pending alias deletion
 */
async function performDelete() {
  if (!pendingDeleteId.value) return
  isDeleting.value = true
  try {
    await termAliasService.delete(pendingDeleteId.value)
    toast.success('Alias deleted')
    confirmOpen.value = false
    pendingDeleteId.value = null
    currentPage.value = 1
    await fetchAliases()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to delete alias')
  } finally {
    isDeleting.value = false
  }
}
</script>
