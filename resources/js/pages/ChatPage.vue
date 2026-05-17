<template>
  <div class="flex flex-col md:flex-row gap-4 md:gap-6 flex-1 min-h-0">
    <aside class="w-full md:w-72 flex-shrink-0 bg-white border border-surface-200 rounded-card flex flex-col overflow-hidden md:max-h-none max-h-[40vh]">
      <div class="p-4 border-b border-surface-200 flex-shrink-0">
        <AppButton variant="primary" block @click="startNewChat">New Chat</AppButton>
      </div>
      <div class="flex-1 min-h-0 overflow-y-auto p-2">
        <SessionList
          :sessions="sessions"
          :active-id="currentSessionId"
          :loading="sessionsLoading"
          @select="selectSession"
          @delete="askDeleteSession"
        />
        <AppButton
          v-if="hasMoreSessions"
          variant="ghost"
          size="sm"
          block
          class="mt-2"
          :loading="sessionsLoading"
          @click="store.loadMoreSessions()"
        >
          Load more
        </AppButton>
      </div>
    </aside>

    <section class="flex-1 min-h-0 bg-white border border-surface-200 rounded-card overflow-hidden flex flex-col">
      <ChatInterface />
    </section>

    <AppConfirm
      v-model="confirmOpen"
      title="Delete chat session?"
      :message="confirmMessage"
      confirm-label="Delete"
      confirm-variant="danger"
      confirm-loading-label="Deleting…"
      :loading="isDeleting"
      @confirm="performDeleteSession"
      @cancel="pendingDeleteId = null"
    />
  </div>
</template>

<script setup lang="ts">
/**
 * Chat Page
 *
 * Main chat view with a sidebar for session management and the main
 * ChatInterface for conversation. Supports session selection, deletion
 * with confirmation, and creating new chats.
 *
 * @prop {void} - This page is route-driven; no custom props
 * @emits {void} - All side-effects are store actions
 */
import { ref, computed, onMounted } from 'vue'
import { useChatStore } from '../stores/chatStore'
import { storeToRefs } from 'pinia'
import { useToast } from '../composables/useToast'
import AppButton from '../components/ui/AppButton.vue'
import AppConfirm from '../components/ui/AppConfirm.vue'
import ChatInterface from '../components/ChatInterface.vue'
import SessionList from '../components/SessionList.vue'

const store = useChatStore()
const toast = useToast()
const { sessions, currentSessionId, sessionsLoading, hasMoreSessions } = storeToRefs(store)

const confirmOpen = ref(false)
const pendingDeleteId = ref<string | null>(null)
const isDeleting = ref(false)

const pendingSession = computed(() =>
  pendingDeleteId.value ? sessions.value.find((s) => s.id === pendingDeleteId.value) : null,
)
const confirmMessage = computed(() => {
  const title = pendingSession.value?.title ?? 'this chat session'
  return `"${title}" and all its messages will be permanently removed.`
})

onMounted(async () => {
  await store.fetchSessions()
})

/**
 * Select and load a session's messages
 *
 * @param {string} id Session ULID. Example: "01J..."
 */
async function selectSession(id: string) {
  await store.fetchSession(id)
}

/**
 * Open delete confirmation for a session
 *
 * @param {string} id Session ULID. Example: "01J..."
 */
function askDeleteSession(id: string) {
  pendingDeleteId.value = id
  confirmOpen.value = true
}

/**
 * Execute the pending session deletion
 */
async function performDeleteSession() {
  if (!pendingDeleteId.value) return
  isDeleting.value = true
  try {
    await store.deleteSession(pendingDeleteId.value)
    toast.success('Chat session deleted')
    confirmOpen.value = false
    pendingDeleteId.value = null
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Failed to delete session')
  } finally {
    isDeleting.value = false
  }
}

/**
 * Start a new chat session
 */
function startNewChat() {
  store.startNewChat()
}
</script>
