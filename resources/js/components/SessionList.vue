<template>
  <div class="space-y-1">
    <div v-if="sessions.length === 0" class="text-sm text-gray-500 text-center py-8">
      No chat sessions yet
    </div>
    <button
      v-for="session in sessions"
      :key="session.id"
      @click="$emit('select', session.id)"
      :class="[
        'w-full text-left px-3 py-2 rounded-lg text-sm transition-colors',
        activeId === session.id
          ? 'bg-blue-50 text-blue-700 font-medium'
          : 'text-gray-700 hover:bg-gray-100'
      ]"
    >
      <p class="truncate">{{ session.title }}</p>
      <p class="text-xs text-gray-400 mt-1">{{ formatDate(session.last_activity_at) }}</p>
    </button>
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
}>()

function formatDate(date: string): string {
  return new Date(date).toLocaleDateString()
}
</script>
