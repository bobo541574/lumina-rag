export interface User {
  id: string
  name: string
  email: string
}

export interface Source {
  document_id: string
  document_title: string
  chunk_index: number
  page_number: number | null
  similarity_score: number
  excerpt: string
}

export interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  sources?: Source[]
  tokens_used?: number
  created_at: string
}

export interface ChatSession {
  id: string
  title: string
  is_archived: boolean
  message_count: number
  last_activity_at: string
  created_at: string
  messages?: ChatMessage[]
}

export interface Document {
  id: string
  title: string
  original_filename: string
  file_size: number
  mime_type: string
  status: 'pending' | 'processing' | 'completed' | 'failed'
  chunks_count: number
  embedding_model?: string
  error_message?: string
  processed_at?: string
  created_at: string
}

export interface ApiResponse<T> {
  success: boolean
  message?: string
  data: T
  errors?: Record<string, string[]>
}
