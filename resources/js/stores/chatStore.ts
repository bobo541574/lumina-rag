import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { ChatMessage, ChatSession, Source } from '../types'
import { chatService } from '../services/chatService'

export const useChatStore = defineStore('chat', () => {
  const sessions = ref<ChatSession[]>([])
  const currentSession = ref<ChatSession | null>(null)
  const messages = ref<ChatMessage[]>([])
  const isLoading = ref(false)
  const isStreaming = ref(false)
  const error = ref<string | null>(null)

  const currentSessionId = computed(() => currentSession.value?.id ?? null)

  async function fetchSessions() {
    try {
      const response = await chatService.listSessions()
      sessions.value = response.data?.data ?? []
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to load sessions'
    }
  }

  async function fetchSession(id: string) {
    try {
      const response = await chatService.getSession(id)
      currentSession.value = response.data
      messages.value = response.data.messages ?? []
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Session not found'
    }
  }

  async function sendMessage(question: string) {
    if (!question.trim()) return

    const userMessage: ChatMessage = {
      id: `temp-${Date.now()}`,
      role: 'user',
      content: question,
      created_at: new Date().toISOString(),
    }
    messages.value.push(userMessage)
    isLoading.value = true
    error.value = null

    try {
      const response = await chatService.ask(question, currentSessionId.value ?? undefined)
      currentSession.value = response.data.session
        ? (response.data.session as ChatSession)
        : { id: response.data.session_id, title: '', is_archived: false, message_count: 0, last_activity_at: '', created_at: '' }

      const assistantMessage = response.data.message as ChatMessage
      messages.value.push(assistantMessage)
      await fetchSessions()
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to get answer'
    } finally {
      isLoading.value = false
    }
  }

  async function deleteSession(id: string) {
    try {
      await chatService.deleteSession(id)
      sessions.value = sessions.value.filter(s => s.id !== id)
      if (currentSession.value?.id === id) {
        currentSession.value = null
        messages.value = []
      }
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to delete session'
    }
  }

  function startNewChat() {
    currentSession.value = null
    messages.value = []
  }

  function clearError() {
    error.value = null
  }

  return {
    sessions,
    currentSession,
    messages,
    isLoading,
    isStreaming,
    error,
    currentSessionId,
    fetchSessions,
    fetchSession,
    sendMessage,
    deleteSession,
    startNewChat,
    clearError,
  }
})
