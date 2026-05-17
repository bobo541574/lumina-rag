<template>
  <div class="space-y-1">
    <!-- Skeleton (initial fetch) -->
    <div v-if="loading && sessions.length === 0" aria-busy="true" aria-label="Loading chat sessions" class="space-y-2 px-2 py-2">
      <div v-for="i in 5" :key="i" class="space-y-1.5">
        <div class="h-3 bg-surface-200 rounded animate-pulse" :style="{ width: `${60 + ((i * 7) % 30)}%` }" />
        <div class="h-2 w-16 bg-surface-100 rounded animate-pulse" />
      </div>
    </div>

    <AppEmptyState
      v-else-if="sessions.length === 0"
      icon="chat"
      title="No chat sessions yet"
      description="Start a new chat to see it here."
    />
    <div
      v-for="session in sessions"
      :key="session.id"
      :class="[
        'group flex items-stretch rounded-lg transition-colors relative',
        activeId === session.id ? 'bg-brand-50' : 'hover:bg-surface-100',
      ]"
    >
      <!-- Active session left accent bar -->
      <span
        v-if="activeId === session.id"
        class="absolute left-0 top-1.5 bottom-1.5 w-1 rounded-r-full bg-brand-600"
        aria-hidden="true"
      />
      <AppButton
        align="left"
        block
        variant="ghost"
        :class="[
          'flex-1 min-w-0 hover:!bg-transparent !rounded-r-none',
          activeId === session.id && '!text-brand-700',
        ]"
        @click="$emit('select', session.id)"
      >
        <span class="flex-1 min-w-0">
          <span :class="['block truncate text-sm', activeId === session.id ? 'font-medium' : '']">
            {{ session.title }}
          </span>
          <span class="block text-xs text-surface-400 mt-1" :title="absoluteTime(session.last_activity_at)">
            {{ formatRelativeTime(session.last_activity_at) }}
          </span>
        </span>
      </AppButton>
      <AppButton
        variant="danger-ghost"
        size="sm"
        aria-label="Delete session"
        :class="[
          'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 mr-1 self-center !rounded-lg',
        ]"
        @click.stop="$emit('delete', session.id)"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </AppButton>
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * Session List
 *
 * Sidebar list of chat sessions with selection highlighting and delete action.
 * Each session shows its title and relative timestamp. The active session has
 * a left accent bar and brand styling.
 *
 * @prop {ChatSession[]} sessions - Chat sessions to display. Example: [{ id: "01J...", title: "Q3 Discussion", ... }]
 * @prop {string|null} activeId - ULID of the currently active session. Example: "01J..."
 * @prop {boolean} [loading=false] - Show skeleton loader when true
 *
 * @emits {select} (id: string) - User clicked a session. Example: select("01J...")
 * @emits {delete} (id: string) - User clicked delete. Example: delete("01J...")
 */
import AppButton from './ui/AppButton.vue'
import AppEmptyState from './ui/AppEmptyState.vue'
import { formatRelativeTime, formatAbsoluteTime } from '../utils/dates'
import type { ChatSession } from '../types'

withDefaults(defineProps<{
  sessions: ChatSession[]
  activeId: string | null
  loading?: boolean
}>(), {
  loading: false,
})

defineEmits<{
  select: [id: string]
  delete: [id: string]
}>()

/**
 * Format a date as absolute time for use in a tooltip
 *
 * @param {string} date ISO-8601 date string. Example: "2026-05-17T12:00:00Z"
 * @returns {string} Formatted absolute date. Example: "May 17, 2026 12:00 PM"
 */
function absoluteTime(date: string): string {
  return formatAbsoluteTime(date)
}
</script>
