import { get, post, del } from './api'
import type { ChatMessage, ChatSession, Source } from '../types'

export type StreamingCallbacks = {
  onSources: (sources: Source[]) => void
  onChunk: (content: string) => void
  onDone: (sessionId: string) => void
  onError: (message: string) => void
}

export const chatService = {
  async ask(question: string, sessionId?: string, documentFilter?: Record<string, unknown>) {
    return post<{ session_id: string; message: ChatMessage }>('/chat', {
      question,
      session_id: sessionId,
      stream: false,
      document_filter: documentFilter,
    })
  },

  askStreaming(
    question: string,
    sessionId: string | undefined,
    callbacks: StreamingCallbacks,
  ): AbortController {
    const controller = new AbortController()
    const token = localStorage.getItem('lumina_token')

    fetch('/api/chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({ question, session_id: sessionId, stream: true }),
      signal: controller.signal,
    }).then(async (response) => {
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
              case 'done':
                callbacks.onDone(data.session_id)
                break
              case 'error':
                callbacks.onError(data.message)
                break
            }
          } catch {
            // ignore malformed JSON lines
          }
        }
      }
    }).catch((err) => {
      if (err.name !== 'AbortError') {
        callbacks.onError(err.message ?? 'Stream connection failed')
      }
    })

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
