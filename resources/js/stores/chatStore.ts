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
  const streamAbortController = ref<AbortController | null>(null)

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

  const documentFilter = ref<Record<string, unknown> | null>(null)

  async function sendMessage(question: string, filter?: Record<string, unknown>, llmModelId?: string) {
    if (!question.trim()) return

    const userMessage: ChatMessage = {
      id: `temp-${Date.now()}`,
      role: 'user',
      content: question,
      created_at: new Date().toISOString(),
    }
    messages.value.push(userMessage)

    const assistantId = `stream-${Date.now()}`
    messages.value.push({
      id: assistantId,
      role: 'assistant',
      content: '',
      sources: [],
      created_at: new Date().toISOString(),
    })

    isStreaming.value = true
    isLoading.value = true
    error.value = null

    let finalContent = ''

    streamAbortController.value = chatService.askStreaming(
      question,
      currentSessionId.value ?? undefined,
      {
        onSources(sources) {
          const msg = messages.value.find(m => m.id === assistantId)
          if (msg) msg.sources = sources
        },
        onChunk(chunk) {
          finalContent += chunk
          const msg = messages.value.find(m => m.id === assistantId)
          if (msg) msg.content = finalContent
        },
        onDone(sessionId) {
          currentSession.value = {
            id: sessionId,
            title: '',
            is_archived: false,
            message_count: 0,
            last_activity_at: new Date().toISOString(),
            created_at: new Date().toISOString(),
          }
          const msg = messages.value.find(m => m.id === assistantId)
          if (msg) msg.id = `${sessionId}-${Date.now()}`
          isStreaming.value = false
          isLoading.value = false
          fetchSessions()
        },
        onError(message) {
          error.value = message
          const msg = messages.value.find(m => m.id === assistantId)
          if (msg && !msg.content) msg.content = message
          isStreaming.value = false
          isLoading.value = false
        },
      },
      filter ?? documentFilter.value ?? undefined,
      llmModelId,
    )
  }

  function abortStream() {
    if (streamAbortController.value) {
      streamAbortController.value.abort()
      streamAbortController.value = null
    }
    isStreaming.value = false
    isLoading.value = false
    messages.value = messages.value.filter(m => !m.id.startsWith('stream-'))
  }

  async function sendMessageNonStreaming(question: string, llmModelId?: string) {
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
      const response = await chatService.ask(question, currentSessionId.value ?? undefined, undefined, llmModelId)
      currentSession.value = {
        id: response.data.session_id,
        title: '',
        is_archived: false,
        message_count: 0,
        last_activity_at: new Date().toISOString(),
        created_at: new Date().toISOString(),
      }

      messages.value.push(response.data.message)
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
    abortStream()
    currentSession.value = null
    messages.value = []
  }

  function clearError() {
    error.value = null
  }

  function setDocumentFilter(filter: Record<string, unknown> | null) {
    documentFilter.value = filter
  }

  return {
    sessions,
    currentSession,
    messages,
    isLoading,
    isStreaming,
    error,
    currentSessionId,
    documentFilter,
    fetchSessions,
    fetchSession,
    sendMessage,
    sendMessageNonStreaming,
    deleteSession,
    startNewChat,
    abortStream,
    clearError,
    setDocumentFilter,
  }
})
