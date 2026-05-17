import { get, post, del } from './api'
import type { ChatMessage, ChatSession, Source } from '../types'

/**
 * Streaming response metadata
 *
 * Performance metrics returned with the 'done' SSE event.
 *
 * @property {number} [tokens_used] Total tokens used by the LLM. Example: 452
 * @property {number} [search_time_ms] Vector search time in ms. Example: 120
 * @property {number} [llm_time_ms] LLM generation time in ms. Example: 3400
 * @property {number} [total_time_ms] Total end-to-end time in ms. Example: 3600
 */
export type StreamMeta = {
  tokens_used?: number
  search_time_ms?: number
  llm_time_ms?: number
  total_time_ms?: number
}

/**
 * Streaming SSE callbacks
 *
 * Callbacks invoked during an SSE chat stream. Each callback receives
 * the parsed data from the corresponding event type.
 *
 * @property {function} onSources Called when source citations arrive. Example: (sources) => { ... }
 * @property {function} onChunk Called for each text chunk. Example: (content) => { ... }
 * @property {function} onDone Called when the stream completes. Example: (sessionId, meta) => { ... }
 * @property {function} onError Called when an error occurs. Example: (message) => { ... }
 * @property {function} onStatus Called with processing stage updates. Example: (stage, message) => { ... }
 */
export type StreamingCallbacks = {
  onSources: (sources: Source[]) => void
  onChunk: (content: string) => void
  onDone: (sessionId: string, meta?: StreamMeta) => void
  onError: (message: string) => void
  onStatus: (stage: string, message: string) => void
}

/**
 * Chat API service
 *
 * Handles synchronous and streaming question answering, session listing,
 * detail fetching, and deletion. Streaming uses SSE with automatic retry.
 */
export const chatService = {
  /**
   * Send a question synchronously (non-streaming)
   *
   * Posts the question to the chat endpoint with `stream: false`. Returns
   * the full assistant response including sources.
   *
   * @param {string} question The user's question. Example: "What is Project Orion?"
   * @param {string} [sessionId] Existing session ULID for continuation. Example: "01J..."
   * @param {Record<string, unknown>} [documentFilter] Optional document filter. Example: { project: "Orion" }
   * @param {string} [llmModelId] Override LLM model ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<{ session_id: string; message: ChatMessage }>>} Session ID and assistant message. Example: { success: true, data: { session_id: "01J...", message: { role: "assistant", ... } } }
   */
  async ask(question: string, sessionId?: string, documentFilter?: Record<string, unknown>, llmModelId?: string) {
    return post<{ session_id: string; message: ChatMessage }>('/chat', {
      question,
      session_id: sessionId,
      stream: false,
      document_filter: documentFilter,
      ...(llmModelId ? { llm_model_id: llmModelId } : {}),
    })
  },

  /**
   * Send a question with streaming response (SSE)
   *
   * Opens an EventStream connection to the chat endpoint with `stream: true`.
   * Parses SSE data events and invokes the corresponding callbacks. Implements
   * exponential-backoff retry (up to 3 attempts) on connection drops.
   *
   * @param {string} question The user's question. Example: "What is Project Orion?"
   * @param {string|undefined} sessionId Existing session ULID for continuation. Example: "01J..."
   * @param {StreamingCallbacks} callbacks SSE event handlers. Example: { onChunk: (c) => ..., onDone: (id, m) => ... }
   * @param {Record<string, unknown>} [documentFilter] Optional document filter. Example: { project: "Orion" }
   * @param {string} [llmModelId] Override LLM model ULID. Example: "01J..."
   * @returns {AbortController} Controller to abort the stream. Example: const ctrl = chatService.askStreaming(...); ctrl.abort()
   */
  askStreaming(
    question: string,
    sessionId: string | undefined,
    callbacks: StreamingCallbacks,
    documentFilter?: Record<string, unknown>,
    llmModelId?: string,
  ): AbortController {
    const controller = new AbortController()
    const token = localStorage.getItem('lumina_token')

    const MAX_RETRIES = 3
    const BASE_DELAY = 1000

    let finished = false
    let retryAttempt = 0

    async function run(): Promise<void> {
      while (retryAttempt <= MAX_RETRIES && !controller.signal.aborted && !finished) {
        try {
          const response = await fetch('/api/chat', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'text/event-stream',
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: JSON.stringify({
              question,
              session_id: sessionId,
              stream: true,
              ...(documentFilter ? { document_filter: documentFilter } : {}),
              ...(llmModelId ? { llm_model_id: llmModelId } : {}),
            }),
            signal: controller.signal,
          })

          if (!response.ok) {
            const body = await response.json().catch(() => null)
            callbacks.onError(body?.message ?? `Request failed with status ${response.status}`)
            return
          }

          const reader = response.body?.getReader()
          if (!reader) {
            callbacks.onError('Response body is not readable')
            return
          }

          const decoder = new TextDecoder()
          let buffer = ''

          while (true) {
            const { done, value } = await reader.read()
            if (done) break

            buffer += decoder.decode(value, { stream: true })
            const lines = buffer.split('\n')
            buffer = lines.pop() ?? ''

            for (const line of lines) {
              const trimmed = line.trim()
              if (!trimmed.startsWith('data: ')) continue

              try {
                const data = JSON.parse(trimmed.slice(6))
                switch (data.type) {
                  case 'chunk':
                    callbacks.onChunk(data.content)
                    break
                  case 'sources':
                    callbacks.onSources(data.sources)
                    break
                  case 'status':
                    callbacks.onStatus(data.stage, data.message)
                    break
                  case 'done':
                    finished = true
                    callbacks.onDone(data.session_id, {
                      tokens_used: data.tokens_used,
                      search_time_ms: data.search_time_ms,
                      llm_time_ms: data.llm_time_ms,
                      total_time_ms: data.total_time_ms,
                    })
                    break
                  case 'error':
                    callbacks.onError(data.message)
                    return
                }
              } catch {
                // ignore malformed JSON lines
              }
            }
          }

          // Natural stream end without 'done' event — reconnect
          if (!controller.signal.aborted && !finished) {
            retryAttempt++
            if (retryAttempt <= MAX_RETRIES) {
              await new Promise((r) => setTimeout(r, BASE_DELAY * Math.pow(2, retryAttempt - 1)))
            }
          }
        } catch (err: any) {
          if (err.name === 'AbortError') return
          retryAttempt++
          if (retryAttempt <= MAX_RETRIES) {
            await new Promise((r) => setTimeout(r, BASE_DELAY * Math.pow(2, retryAttempt - 1)))
          } else {
            callbacks.onError(err.message ?? 'Stream connection failed')
          }
        }
      }
    }

    run()

    return controller
  },

  /**
   * List chat sessions with pagination
   *
   * @param {number} [page] Page number (1-based). Example: 1
   * @returns {Promise<ApiResponse<ChatSession[]>>} Paginated list of sessions. Example: { success: true, data: [{ id: "01J...", ... }], meta: {...} }
   */
  async listSessions(page = 1) {
    return get<ChatSession[]>('/chat/sessions', { page })
  },

  /**
   * Get a single chat session with messages
   *
   * @param {string} id Session ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<ChatSession>>} Session with messages array. Example: { success: true, data: { id: "01J...", messages: [...], ... } }
   */
  async getSession(id: string) {
    return get<ChatSession>(`/chat/sessions/${id}`)
  },

  /**
   * Delete a chat session
   *
   * @param {string} id Session ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<null>>} Empty success response. Example: { success: true, message: "Session deleted" }
   */
  async deleteSession(id: string) {
    return del(`/chat/sessions/${id}`)
  },
}
