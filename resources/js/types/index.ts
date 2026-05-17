/**
 * Authenticated user
 *
 * Represents a registered user returned by the auth endpoints. PK is a ULID.
 * The name and email are set during registration.
 *
 * @property {string} id User ULID. Example: "01J..."
 * @property {string} name Display name. Example: "Alice"
 * @property {string} email Email address. Example: "alice@example.com"
 */
export interface User {
  id: string
  name: string
  email: string
}

/**
 * Document source citation
 *
 * A single source chunk retrieved by the RAG pipeline and cited in an assistant
 * response. Contains metadata about the source document, the chunk's position,
 * and the similarity score from vector search.
 *
 * @property {string} document_id ULID of the source document. Example: "01J..."
 * @property {string} document_title Title of the source document. Example: "Q3 Report"
 * @property {number} chunk_index Zero-based index of the chunk within the document. Example: 4
 * @property {number|null} page_number Page number if available. Example: 12
 * @property {number} similarity_score Cosine similarity score (0-1). Example: 0.87
 * @property {string} excerpt Snippet of the chunk content. Example: "Project Orion was initiated in..."
 */
export interface Source {
  document_id: string
  document_title: string
  chunk_index: number
  page_number: number | null
  similarity_score: number
  excerpt: string
}

/**
 * Chat message
 *
 * A single message in a chat session, either from the user or the assistant.
 * Assistant messages may include source citations and token usage metadata.
 *
 * @property {string} id Message ULID (temp IDs for optimistic inserts). Example: "01J..."
 * @property {'user'|'assistant'} role Message author. Example: "user"
 * @property {string} content Message body text. Example: "What is Project Orion?"
 * @property {Source[]} [sources] Source citations (assistant only). Example: [{ document_id: "01J...", ... }]
 * @property {number} [tokens_used] Total tokens consumed for this response. Example: 452
 * @property {string} created_at ISO-8601 timestamp. Example: "2026-05-17T12:00:00Z"
 */
export interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  sources?: Source[]
  tokens_used?: number
  created_at: string
}

/**
 * Chat session
 *
 * A conversation between a user and the assistant, containing a sequence of
 * messages. Sessions are soft-deletable and have a title auto-generated from
 * the first exchange.
 *
 * @property {string} id Session ULID. Example: "01J..."
 * @property {string} title Auto-generated or user-set title. Example: "Q3 Discussion"
 * @property {boolean} is_archived Whether the session is archived. Example: false
 * @property {number} message_count Total messages in the session. Example: 12
 * @property {string} last_activity_at ISO-8601 timestamp of last message. Example: "2026-05-17T12:00:00Z"
 * @property {string} created_at ISO-8601 timestamp. Example: "2026-05-17T11:00:00Z"
 * @property {ChatMessage[]} [messages] Messages (included on detail fetch). Example: [{ role: "user", ... }]
 */
export interface ChatSession {
  id: string
  title: string
  is_archived: boolean
  message_count: number
  last_activity_at: string
  created_at: string
  messages?: ChatMessage[]
}

/**
 * Uploaded document
 *
 * Represents a file uploaded by the user for ingestion into the RAG pipeline.
 * Tracks processing status, chunk count, and the embedding model used.
 * Documents are soft-deleted.
 *
 * @property {string} id Document ULID. Example: "01J..."
 * @property {string} title Display title. Example: "Q3 Financial Report"
 * @property {string} [description] Rich-text description (Trix HTML). Example: "<div>Report for Q3</div>"
 * @property {string} original_filename Original upload filename. Example: "q3_report.pdf"
 * @property {number} file_size Size in bytes. Example: 245760
 * @property {string} mime_type MIME type. Example: "application/pdf"
 * @property {'pending'|'processing'|'completed'|'failed'} status Processing status. Example: "completed"
 * @property {number} chunks_count Number of chunks extracted. Example: 24
 * @property {string} [embedding_model] Model name used for embeddings. Example: "text-embedding-3-small"
 * @property {string} [embedding_model_id] FK to ai_models. Example: "01J..."
 * @property {string} [report_date] Associated report date. Example: "2026-03-15"
 * @property {string} [project] Project label. Example: "Project Orion"
 * @property {string} [error_message] Error info if status is "failed". Example: "PDF parsing error"
 * @property {string} [processed_at] ISO-8601 completion timestamp. Example: "2026-05-17T12:00:00Z"
 * @property {string} created_at ISO-8601 timestamp. Example: "2026-05-17T11:00:00Z"
 */
export interface Document {
  id: string
  title: string
  description?: string
  original_filename: string
  file_size: number
  mime_type: string
  status: 'pending' | 'processing' | 'completed' | 'failed'
  chunks_count: number
  embedding_model?: string
  embedding_model_id?: string
  report_date?: string
  project?: string
  error_message?: string
  processed_at?: string
  created_at: string
}

/**
 * AI model registry entry
 *
 * Configuration record for an embedding or LLM model. Each model references a
 * provider (openai/ollama), the model name, and optional overrides. Active
 * models are selected via is_active + sort_order.
 *
 * @property {string} id Model ULID. Example: "01J..."
 * @property {string} name Human-readable name. Example: "OpenAI Embedding Small"
 * @property {'embedding'|'llm'} type Model category. Example: "embedding"
 * @property {string} provider Provider slug. Example: "openai"
 * @property {string} model Model identifier. Example: "text-embedding-3-small"
 * @property {string} [api_key] Optional per-model API key override. Example: "sk-..."
 * @property {string} [base_url] Optional base URL override. Example: "https://api.openai.com/v1"
 * @property {string} [collection] Ollama collection name. Example: "nomic-embed-text"
 * @property {number} [dimensions] Vector dimensions. Example: 1536
 * @property {number} [batch_size] Embedding batch size. Example: 32
 * @property {number} [cache_ttl] Embedding cache TTL in seconds. Example: 86400
 * @property {number} [temperature] LLM temperature. Example: 0.3
 * @property {number} [max_context_tokens] Max context window tokens. Example: 8192
 * @property {number} [timeout] Request timeout in seconds. Example: 60
 * @property {string} [description] Model description. Example: "Best for general purpose"
 * @property {Record<string, unknown>} [settings] Arbitrary pipeline overrides. Example: { topK: 10 }
 * @property {boolean} is_active Whether this model is active. Example: true
 * @property {number} sort_order Priority order (lower = higher). Example: 1
 * @property {string} created_at ISO-8601 timestamp. Example: "2026-05-17T12:00:00Z"
 * @property {string} updated_at ISO-8601 timestamp. Example: "2026-05-17T12:00:00Z"
 */
export interface AiModel {
  id: string
  name: string
  type: 'embedding' | 'llm'
  provider: string
  model: string
  api_key?: string
  base_url?: string
  collection?: string
  dimensions?: number
  batch_size?: number
  cache_ttl?: number
  temperature?: number
  max_context_tokens?: number
  timeout?: number
  description?: string
  settings?: Record<string, unknown>
  is_active: boolean
  sort_order: number
  created_at: string
  updated_at: string
}

/**
 * Pagination metadata
 *
 * Standard Laravel pagination metadata returned alongside paginated API responses.
 * Mirrors the structure of `Illuminate\Pagination\LengthAwarePaginator`.
 *
 * @property {number} current_page 1-based current page. Example: 1
 * @property {number} last_page Last available page. Example: 5
 * @property {number} per_page Items per page. Example: 25
 * @property {number} total Total items across all pages. Example: 112
 * @property {number|null} from Index of first item on this page. Example: 1
 * @property {number|null} to Index of last item on this page. Example: 25
 */
export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

/**
 * Term alias mapping
 *
 * Maps alternative search terms to canonical names. Used during query
 * expansion to improve recall. For example, "quarterly" → "quarterly report".
 *
 * @property {string} id Alias ULID. Example: "01J..."
 * @property {'project'|'technical'|'general'} type Category. Example: "project"
 * @property {string} alias The alternative/short form. Example: "quarterly"
 * @property {string} canonical The canonical/expanded form. Example: "quarterly report"
 * @property {string} [description] Optional description. Example: "Common abbreviation"
 * @property {boolean} is_active Whether this alias is active. Example: true
 * @property {string} created_at ISO-8601 timestamp. Example: "2026-05-17T12:00:00Z"
 * @property {string} updated_at ISO-8601 timestamp. Example: "2026-05-17T12:00:00Z"
 */
export interface TermAlias {
  id: string
  type: 'project' | 'technical' | 'general'
  alias: string
  canonical: string
  description?: string
  is_active: boolean
  created_at: string
  updated_at: string
}

/**
 * Standard API response envelope
 *
 * Every API endpoint returns this structure. Success/failure is indicated by
 * the `success` boolean. Paginated responses include a `meta` block.
 *
 * @property {boolean} success Whether the request succeeded. Example: true
 * @property {string} [message] Human-readable status message. Example: "Document uploaded"
 * @property {T} data The response payload. Example: { id: "01J...", ... }
 * @property {Record<string, string[]>} [errors] Validation errors keyed by field. Example: { email: ["The email field is required."] }
 * @property {PaginationMeta} [meta] Pagination metadata for list endpoints. Example: { current_page: 1, ... }
 */
export interface ApiResponse<T> {
  success: boolean
  message?: string
  data: T
  errors?: Record<string, string[]>
  meta?: PaginationMeta
}
