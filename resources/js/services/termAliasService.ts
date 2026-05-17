import { get, post, put, del } from './api'
import type { TermAlias } from '../types'

/**
 * Term alias API service
 *
 * CRUD operations for term alias mappings used during query expansion.
 * Aliases map alternative search terms (e.g. "quarterly") to canonical names
 * (e.g. "quarterly report").
 */
export const termAliasService = {
  /**
   * List all term aliases with optional filtering and pagination
   *
   * @param {string} [type] Filter by type: "project" | "technical" | "general". Example: "project"
   * @param {number} [page] Page number (1-based). Example: 1
   * @param {number} [perPage] Items per page (server caps at 100). Example: 20
   * @returns {Promise<ApiResponse<TermAlias[]>>} Paginated list of aliases. Example: { success: true, data: [{ id: "01J...", alias: "...", ... }], meta: {...} }
   */
  async getAll(type?: string, page?: number, perPage?: number) {
    const params: Record<string, unknown> = {}
    if (type) params.type = type
    if (page) params.page = page
    if (perPage) params.per_page = perPage
    return get<TermAlias[]>('/settings/term-aliases', params)
  },

  /**
   * Get a single term alias by ID
   *
   * @param {string} id Alias ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<TermAlias>>} The alias record. Example: { success: true, data: { id: "01J...", alias: "quarterly", ... } }
   */
  async get(id: string) {
    return get<TermAlias>(`/settings/term-aliases/${id}`)
  },

  /**
   * Create a new term alias
   *
   * @param {Partial<TermAlias>} data Alias data. Example: { alias: "quarterly", canonical: "quarterly report", type: "project" }
   * @returns {Promise<ApiResponse<TermAlias>>} The created alias. Example: { success: true, data: { id: "01J...", ... } }
   */
  async create(data: Partial<TermAlias>) {
    return post<TermAlias>('/settings/term-aliases', data)
  },

  /**
   * Update an existing term alias
   *
   * @param {string} id Alias ULID. Example: "01J..."
   * @param {Partial<TermAlias>} data Fields to update. Example: { is_active: false }
   * @returns {Promise<ApiResponse<TermAlias>>} The updated alias. Example: { success: true, data: { id: "01J...", ... } }
   */
  async update(id: string, data: Partial<TermAlias>) {
    return put<TermAlias>(`/settings/term-aliases/${id}`, data)
  },

  /**
   * Delete a term alias
   *
   * @param {string} id Alias ULID. Example: "01J..."
   * @returns {Promise<ApiResponse<null>>} Empty success response. Example: { success: true, message: "Alias deleted" }
   */
  async delete(id: string) {
    return del(`/settings/term-aliases/${id}`)
  },
}
