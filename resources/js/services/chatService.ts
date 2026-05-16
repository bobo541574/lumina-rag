import { get, post, del } from './api'
import type { ChatMessage, ChatSession, Source } from '../types'

export type StreamMeta = {
  tokens_used?: number
  search_time_ms?: number
  llm_time_ms?: number
  total_time_ms?: number
}

export type StreamingCallbacks = {
  onSources: (sources: Source[]) => void
  onChunk: (content: string) => void
  onDone: (sessionId: string, meta?: StreamMeta) => void
  onError: (message: string) => void
  onStatus: (stage: string, message: string) => void
}

export const chatService = {
  async ask(question: string, sessionId?: string, documentFilter?: Record<string, unknown>, llmModelId?: string) {
    return post<{ session_id: string; message: ChatMessage }>('/chat', {
      question,
      session_id: sessionId,
      stream: false,
      document_filter: documentFilter,
      ...(llmModelId ? { llm_model_id: llmModelId } : {}),
    })
  },

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

  async listSessions() {
    return get<{ data: ChatSession[] }>('/chat/sessions')
  },

  async getSession(id: string) {
    return get<ChatSession>(`/chat/sessions/${id}`)
  },

  async deleteSession(id: string) {
    return del(`/chat/sessions/${id}`)
  },
}
