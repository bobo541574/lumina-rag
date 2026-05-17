import { get, post, put, del } from './api'
import type { AiModel } from '../types'

/**
 * AI model API service
 *
 * CRUD operations for the AI model registry. Models define embedding and LLM
 * providers used by the RAG pipeline.
 */
export const aiModelService = {
  /**
   * List all AI models with optional filtering and pagination
   *
   * @param {string} [type] Filter by model type: "embedding" | "llm". Example: "embedding"
   * @param {number} [page] Page number (1-based). Example: 1
   * @param {number} [perPage] Items per page. Example: 20
   * @returns {Promise<ApiResponse<AiModel[]>>} Paginated list of models. Example: { success: true, data: [{ id: "01J...", ... }], meta: {...} }
   */
  async getAll(type?: string, page?: number, perPage?: number) {
    const params: Record<string, unknown> = {}
    if (type) params.type = type
    if (page) params.page = page
    if (perPage) params.per_page = perPage
    return get<AiModel[]>('/settings/ai-models', params)
  },

  /**
   * Get a single AI model by ID
   *
   * @param {string} id Model ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<AiModel>>} The model record. Example: { success: true, data: { id: "01J...", name: "...", ... } }
   */
  async get(id: string) {
    return get<AiModel>(`/settings/ai-models/${id}`)
  },

  /**
   * Create a new AI model
   *
   * @param {Partial<AiModel>} data Model configuration. Example: { name: "GPT-4o", type: "llm", provider: "openai", ... }
   * @returns {Promise<ApiResponse<AiModel>>} The created model. Example: { success: true, data: { id: "01J...", ... } }
   */
  async create(data: Partial<AiModel>) {
    return post<AiModel>('/settings/ai-models', data)
  },

  /**
   * Update an existing AI model
   *
   * @param {string} id Model ULID. Example: "01J..."
   * @param {Partial<AiModel>} data Fields to update. Example: { is_active: true, temperature: 0.5 }
   * @returns {Promise<ApiResponse<AiModel>>} The updated model. Example: { success: true, data: { id: "01J...", ... } }
   */
  async update(id: string, data: Partial<AiModel>) {
    return put<AiModel>(`/settings/ai-models/${id}`, data)
  },

  /**
   * Delete an AI model
   *
   * @param {string} id Model ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<null>>} Empty success response. Example: { success: true, message: "Model deleted" }
   */
  async delete(id: string) {
    return del(`/settings/ai-models/${id}`)
  },
}
