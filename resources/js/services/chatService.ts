import { get, post, del } from './api'
import type { ChatSession } from '../types'

export const chatService = {
  async ask(question: string, sessionId?: string, documentFilter?: Record<string, unknown>) {
    return post<{ session_id: string; message: ChatSession['messages'] }>('/chat', {
      question,
      session_id: sessionId,
      stream: false,
      document_filter: documentFilter,
    })
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
