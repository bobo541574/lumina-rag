import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { ChatMessage, ChatSession, PaginationMeta, Source } from '../types'
import type { StreamMeta } from '../services/chatService'
import { chatService } from '../services/chatService'

/**
 * Chat Store
 *
 * Manages chat sessions, messages, and streaming state. Orchestrates the
 * send/stream lifecycle with optimistic message insertion, SSE callback
 * handling, abort support, and session management.
 */
export const useChatStore = defineStore('chat', () => {
  const sessions = ref<ChatSession[]>([])
  const sessionsMeta = ref<PaginationMeta | null>(null)
  const sessionsPage = ref(1)
  const sessionsLoading = ref(false)
  const currentSession = ref<ChatSession | null>(null)
  const messages = ref<ChatMessage[]>([])
  const isLoading = ref(false)
  const isStreaming = ref(false)
  const error = ref<string | null>(null)
  const streamAbortController = ref<AbortController | null>(null)
  const currentStage = ref<{ stage: string; message: string } | null>(null)
  const lastStreamMeta = ref<StreamMeta | null>(null)

  const currentSessionId = computed(() => currentSession.value?.id ?? null)
  const hasMoreSessions = computed(() => {
    if (!sessionsMeta.value) return false
    return sessionsMeta.value.current_page < sessionsMeta.value.last_page
  })

  /**
   * Fetch chat sessions with pagination
   *
   * Replaces sessions on page 1, appends for subsequent pages.
   *
   * @param {number} [page] Page number (1-based). Example: 1
   */
  async function fetchSessions(page = 1) {
    sessionsLoading.value = true
    try {
      const response = await chatService.listSessions(page)
      if (page === 1) {
        sessions.value = response.data ?? []
      } else {
        sessions.value = [...sessions.value, ...(response.data ?? [])]
      }
      sessionsMeta.value = response.meta ?? null
      sessionsPage.value = page
    } catch (e: any) {
      error.value = e.response?.data?.message ?? 'Failed to load sessions'
    } finally {
      sessionsLoading.value = false
    }
  }

  /**
   * Load the next page of sessions (appends to current list)
   */
  async function loadMoreSessions() {
    if (!hasMoreSessions.value || sessionsLoading.value) return
    await fetchSessions(sessionsPage.value + 1)
  }

  /**
   * Fetch a single session with its messages
   *
   * @param {string} id Session ULID. Example: "01J..."
   */
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

  /**
   * Send a message with streaming response
   *
   * Inserts optimistic user and assistant messages, then initiates an SSE
   * stream. Updates the assistant message in-place as chunks arrive.
   * Creates a new session on first message.
   *
   * @param {string} question The user's question. Example: "What is Project Orion?"
   * @param {Record<string, unknown>} [filter] Optional document/date filter. Example: { document_ids: ["01J..."] }
   * @param {string} [llmModelId] Override LLM model ULID. Example: "01J..."
   */
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
        onStatus(stage, message) {
          currentStage.value = { stage, message }
        },
        onDone(sessionId, meta) {
          lastStreamMeta.value = meta ?? null
          currentStage.value = null
          currentSession.value = {
            id: sessionId,
            title: '',
            is_archived: false,
            message_count: 0,
            last_activity_at: new Date().toISOString(),
            created_at: new Date().toISOString(),
          }
          const msg = messages.value.find(m => m.id === assistantId)
          if (msg) {
            msg.id = `${sessionId}-${Date.now()}`
            if (meta?.tokens_used) msg.tokens_used = meta.tokens_used
          }
          isStreaming.value = false
          isLoading.value = false
          fetchSessions()
        },
        onError(message) {
          currentStage.value = null
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

  /**
   * Abort the current streaming response
   *
   * Cancels the SSE connection and removes the incomplete assistant message.
   */
  function abortStream() {
    if (streamAbortController.value) {
      streamAbortController.value.abort()
      streamAbortController.value = null
    }
    isStreaming.value = false
    isLoading.value = false
    currentStage.value = null
    messages.value = messages.value.filter(m => !m.id.startsWith('stream-'))
  }

  /**
   * Send a message synchronously (non-streaming fallback)
   *
   * Inserts an optimistic user message, waits for the full response, then
   * appends the assistant message. Suitable for environments where SSE is
   * unavailable.
   *
   * @param {string} question The user's question. Example: "Summarize the report"
   * @param {string} [llmModelId] Override LLM model ULID. Example: "01J..."
   */
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

  /**
   * Delete a session by ID
   *
   * Removes from local list and clears current session if it was deleted.
   *
   * @param {string} id Session ULID. Example: "01J..."
   */
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

  /**
   * Start a new chat session
   *
   * Aborts any in-progress stream and clears messages and current session.
   */
  function startNewChat() {
    abortStream()
    currentSession.value = null
    messages.value = []
  }

  /**
   * Clear the current error state
   */
  function clearError() {
    error.value = null
  }

  /**
   * Set the document filter for subsequent messages
   *
   * @param {Record<string, unknown> | null} filter Filter object or null to clear. Example: { project: "Orion" }
   */
  function setDocumentFilter(filter: Record<string, unknown> | null) {
    documentFilter.value = filter
  }

  return {
    sessions,
    sessionsMeta,
    sessionsPage,
    sessionsLoading,
    currentSession,
    messages,
    isLoading,
    isStreaming,
    error,
    currentSessionId,
    hasMoreSessions,
    documentFilter,
    currentStage,
    lastStreamMeta,
    fetchSessions,
    loadMoreSessions,
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
