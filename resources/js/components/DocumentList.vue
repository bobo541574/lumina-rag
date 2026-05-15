<template>
  <div class="bg-white border border-surface-200 rounded-card overflow-hidden">
    <!-- Skeleton (initial fetch only) -->
    <div v-if="loading && documents.length === 0" class="divide-y divide-surface-100" aria-busy="true" aria-label="Loading documents">
      <div class="flex items-center gap-3 px-4 py-3 bg-surface-50 border-b border-surface-200">
        <div class="h-3 w-16 bg-surface-200 rounded animate-pulse" />
        <div class="h-3 w-12 bg-surface-200 rounded animate-pulse" />
        <div class="h-3 w-12 bg-surface-200 rounded animate-pulse hidden sm:block" />
      </div>
      <div v-for="i in 5" :key="i" class="flex items-center gap-3 px-4 py-3.5">
        <div class="h-4 flex-1 bg-surface-100 rounded animate-pulse" :style="{ maxWidth: `${30 + ((i * 9) % 40)}%` }" />
        <div class="h-5 w-16 bg-surface-200 rounded-full animate-pulse" />
        <div class="h-4 w-12 bg-surface-100 rounded animate-pulse hidden sm:block" />
        <div class="h-4 w-10 bg-surface-100 rounded animate-pulse hidden md:block" />
        <div class="h-4 w-20 bg-surface-100 rounded animate-pulse hidden md:block" />
      </div>
    </div>

    <AppEmptyState
      v-else-if="documents.length === 0"
      icon="document"
      :title="emptyTitle"
      :description="emptyDescription"
    />

    <table v-else class="w-full text-sm">
      <thead>
        <tr class="border-b border-surface-200 bg-surface-50">
          <th v-if="selectable" class="w-10 px-4 py-3">
            <AppCheckbox
              :model-value="allOnPageSelected"
              :indeterminate="someOnPageSelected && !allOnPageSelected"
              :aria-label="allOnPageSelected ? 'Deselect all on this page' : 'Select all on this page'"
              @update:model-value="$emit('toggleSelectAll')"
            />
          </th>
          <th class="text-left p-0">
            <SortHeader :sort-key="'title'" :current-key="sortKey" :current-dir="sortDir" @sort="(k) => $emit('sort', k)">Title</SortHeader>
          </th>
          <th class="text-left p-0">
            <SortHeader :sort-key="'status'" :current-key="sortKey" :current-dir="sortDir" @sort="(k) => $emit('sort', k)">Status</SortHeader>
          </th>
          <th class="text-left p-0 hidden sm:table-cell">
            <SortHeader :sort-key="'file_size'" :current-key="sortKey" :current-dir="sortDir" @sort="(k) => $emit('sort', k)">Size</SortHeader>
          </th>
          <th class="text-left p-0 hidden md:table-cell">
            <SortHeader :sort-key="'chunks_count'" :current-key="sortKey" :current-dir="sortDir" @sort="(k) => $emit('sort', k)">Chunks</SortHeader>
          </th>
          <th class="text-left px-4 py-3 font-medium text-surface-600 hidden lg:table-cell">Model</th>
          <th class="text-left p-0 hidden md:table-cell">
            <SortHeader :sort-key="'created_at'" :current-key="sortKey" :current-dir="sortDir" @sort="(k) => $emit('sort', k)">Uploaded</SortHeader>
          </th>
          <th class="text-right px-4 py-3 font-medium text-surface-600">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="doc in documents"
          :key="doc.id"
          :class="[
            'border-b border-surface-100 transition-colors',
            isSelected(doc.id) ? 'bg-brand-50/50 hover:bg-brand-50' : 'hover:bg-surface-50',
          ]"
        >
          <td v-if="selectable" class="px-4 py-3">
            <AppCheckbox
              :model-value="isSelected(doc.id)"
              :aria-label="`Select ${doc.title}`"
              @update:model-value="$emit('toggleSelect', doc.id)"
            />
          </td>
          <td class="px-4 py-3">
            <AppButton
              variant="ghost"
              size="sm"
              align="left"
              class="!px-0 !py-0 !text-brand-700 hover:!bg-transparent hover:!underline font-medium max-w-xs truncate"
              @click="$emit('view', doc)"
            >
              {{ doc.title }}
            </AppButton>
          </td>
          <td class="px-4 py-3">
            <AppBadge :variant="statusVariant(doc.status)" size="sm">{{ doc.status }}</AppBadge>
          </td>
          <td class="px-4 py-3 text-surface-600 hidden sm:table-cell">{{ formatSize(doc.file_size) }}</td>
          <td class="px-4 py-3 text-surface-600 hidden md:table-cell tabular-nums">{{ doc.chunks_count }}</td>
          <td class="px-4 py-3 hidden lg:table-cell">
            <AppBadge v-if="doc.embedding_model" variant="neutral" size="xs" shape="square" class="!font-mono">
              {{ modelLabel(doc.embedding_model) }}
            </AppBadge>
          </td>
          <td class="px-4 py-3 text-surface-600 hidden md:table-cell" :title="absoluteTime(doc.created_at)">
            {{ formatRelativeTime(doc.created_at) }}
          </td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <div class="inline-flex items-center gap-1">
              <AppButton
                variant="ghost"
                size="sm"
                aria-label="Edit document"
                title="Edit"
                class="!p-1.5"
                @click="$emit('view', doc)"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </AppButton>
              <AppButton
                v-if="doc.status === 'failed'"
                variant="ghost"
                size="sm"
                aria-label="Retry processing"
                title="Retry"
                class="!p-1.5"
                @click="$emit('retry', doc.id)"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582a8.001 8.001 0 0115.356 2M20 20v-5h-.581a8.003 8.003 0 01-15.357-2" />
                </svg>
              </AppButton>
              <AppButton
                variant="danger-ghost"
                size="sm"
                aria-label="Delete document"
                title="Delete"
                class="!p-1.5"
                @click="$emit('delete', doc.id)"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" />
                </svg>
              </AppButton>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import AppButton from './ui/AppButton.vue'
import AppBadge from './ui/AppBadge.vue'
import AppCheckbox from './ui/AppCheckbox.vue'
import AppEmptyState from './ui/AppEmptyState.vue'
import SortHeader from './ui/SortHeader.vue'
import { formatRelativeTime, formatAbsoluteTime } from '../utils/dates'
import type { Document } from '../types'

export type SortKey = 'title' | 'status' | 'file_size' | 'chunks_count' | 'created_at'
export type SortDir = 'asc' | 'desc'

const props = withDefaults(defineProps<{
  documents: Document[]
  sortKey?: SortKey
  sortDir?: SortDir
  selectable?: boolean
  selectedIds?: Set<string>
  emptyTitle?: string
  emptyDescription?: string
  loading?: boolean
}>(), {
  sortKey: 'created_at',
  sortDir: 'desc',
  selectable: false,
  selectedIds: () => new Set<string>(),
  emptyTitle: 'No documents uploaded yet',
  emptyDescription: 'Upload a PDF, DOCX, or TXT file above to get started.',
  loading: false,
})

defineEmits<{
  view: [doc: Document]
  retry: [id: string]
  delete: [id: string]
  sort: [key: SortKey]
  toggleSelect: [id: string]
  toggleSelectAll: []
}>()

const allOnPageSelected = computed(() =>
  props.documents.length > 0 && props.documents.every((d) => props.selectedIds.has(d.id)),
)
const someOnPageSelected = computed(() =>
  props.documents.some((d) => props.selectedIds.has(d.id)),
)

function isSelected(id: string): boolean {
  return props.selectedIds.has(id)
}

function statusVariant(status: string): 'warning' | 'brand' | 'success' | 'danger' | 'neutral' {
  const map: Record<string, 'warning' | 'brand' | 'success' | 'danger' | 'neutral'> = {
    pending: 'warning',
    processing: 'brand',
    completed: 'success',
    failed: 'danger',
  }
  return map[status] ?? 'neutral'
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function modelLabel(model: string): string {
  const map: Record<string, string> = {
    'text-embedding-3-small': '3-small',
    'text-embedding-3-large': '3-large',
    'text-embedding-ada-002': 'ada-002',
  }
  return map[model] ?? model
}

function absoluteTime(date: string): string {
  return formatAbsoluteTime(date)
}
</script>
