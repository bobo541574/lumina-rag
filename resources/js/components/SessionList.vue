<template>
  <div class="space-y-1">
    <div v-if="sessions.length === 0" class="text-sm text-gray-500 text-center py-8">
      No chat sessions yet
    </div>
    <div
      v-for="session in sessions"
      :key="session.id"
      :class="[
        'group flex items-center rounded-lg transition-colors',
        activeId === session.id ? 'bg-blue-50' : 'hover:bg-gray-100'
      ]"
    >
      <button @click="$emit('select', session.id)" class="flex-1 text-left px-3 py-2 min-w-0">
        <p :class="['truncate text-sm', activeId === session.id ? 'text-blue-700 font-medium' : 'text-gray-700']">{{ session.title }}</p>
        <p class="text-xs text-gray-400 mt-1">{{ formatDate(session.last_activity_at) }}</p>
      </button>
      <button
        @click.stop="$emit('delete', session.id)"
        class="opacity-0 group-hover:opacity-100 p-1.5 mr-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50 transition-all"
        title="Delete session"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ChatSession } from '../types'

defineProps<{
  sessions: ChatSession[]
  activeId: string | null
}>()

defineEmits<{
  select: [id: string]
  delete: [id: string]
}>()

function formatDate(date: string): string {
  return new Date(date).toLocaleDateString()
}
</script>
