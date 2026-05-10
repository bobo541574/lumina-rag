<template>
  <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
    <div v-if="documents.length === 0" class="text-center text-gray-500 py-12">
      No documents uploaded yet
    </div>

    <table v-else class="w-full text-sm">
      <thead>
        <tr class="border-b border-gray-200 bg-gray-50">
          <th class="text-left px-4 py-3 font-medium text-gray-600">Title</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Status</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Size</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Chunks</th>
          <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
          <th class="text-right px-4 py-3 font-medium text-gray-600">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="doc in documents" :key="doc.id" class="border-b border-gray-100 hover:bg-gray-50">
          <td class="px-4 py-3 font-medium text-gray-900">{{ doc.title }}</td>
          <td class="px-4 py-3">
            <span :class="statusBadge(doc.status)" class="inline-flex px-2 py-1 text-xs font-medium rounded-full">
              {{ doc.status }}
            </span>
          </td>
          <td class="px-4 py-3 text-gray-600">{{ formatSize(doc.file_size) }}</td>
          <td class="px-4 py-3 text-gray-600">{{ doc.chunks_count }}</td>
          <td class="px-4 py-3 text-gray-600">{{ formatDate(doc.created_at) }}</td>
          <td class="px-4 py-3 text-right">
            <button @click="$emit('delete', doc.id)" class="text-red-600 hover:text-red-700 text-xs font-medium">
              Delete
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import type { Document } from '../types'

defineProps<{
  documents: Document[]
}>()

defineEmits<{
  delete: [id: string]
}>()

function statusBadge(status: string): string {
  const map: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    processing: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
  }
  return map[status] ?? 'bg-gray-100 text-gray-800'
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatDate(date: string): string {
  return new Date(date).toLocaleDateString()
}
</script>
