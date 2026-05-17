<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\SettingsModule\Models\TermAlias;

/**
 * Term Alias Service Interface
 *
 * Defines the contract for managing term alias mappings used during RAG search.
 * Aliases map non-canonical terms (e.g., Burmese translations, abbreviations) to
 * their canonical English equivalents, enabling cross-language and variant-aware
 * search via text expansion and FTS query augmentation.
 *
 * Implementors are responsible for caching the alias map (Redis-backed by default)
 * and providing both text-level and query-level expansion strategies.
 *
 * @method array getAll(?string $type = null, ?int $page = null, ?int $perPage = null) List aliases with optional type filtering and pagination
 * @method \Modules\SettingsModule\Models\TermAlias find(string $id) Find an alias by ULID
 * @method \Modules\SettingsModule\Models\TermAlias create(array $data) Create a new alias
 * @method \Modules\SettingsModule\Models\TermAlias update(string $id, array $data) Update an existing alias
 * @method void delete(string $id) Delete an alias by ULID
 * @method array getAliasMap() Get cached flat mapping of alias → canonical
 * @method string expandText(string $text) Expand text with canonical terms
 * @method string expandFtsQuery(string $ftsQuery) Expand FTS query with OR canonical terms
 * @method void clearCache() Clear the alias cache
 *
 * @throws ModelNotFoundException When find/update/delete target is missing
 */
interface TermAliasServiceInterface
{
    /**
     * List all aliases with optional type filter and pagination
     *
     * When page and perPage are both provided, returns a paginated response
     * with meta information. Otherwise returns the full result set.
     *
     * @param  string|null  $type  Filter by alias type: "project", "technical", or "general". Example: "project"
     * @param  int|null  $page  Page number (1-based). Required with perPage for pagination. Example: 1
     * @param  int|null  $perPage  Items per page. Required with page for pagination. Example: 20
     * @return array{data: array, meta: array|null} List of aliases with optional pagination meta
     *                                              Example: ["data" => [["id" => "01J...", "alias" => "အိုရီယွန်", "canonical" => "Orion", ...]], "meta" => ["current_page" => 1, "last_page" => 3, "total" => 50]]
     */
    public function getAll(?string $type = null, ?int $page = null, ?int $perPage = null): array;

    /**
     * Find a term alias by its ULID
     *
     * Throws ModelNotFoundException if no alias exists with the given ID.
     *
     * @param  string  $id  The ULID of the alias. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return TermAlias The found alias instance
     *                   Example: TermAlias {id: "01J...", alias: "အိုရီယွန်", canonical: "Orion", type: "project"}
     *
     * @throws ModelNotFoundException When no alias matches the ID
     *                                Example: find("nonexistent") → ModelNotFoundException
     */
    public function find(string $id): TermAlias;

    /**
     * Create a new term alias
     *
     * Mass-assigns the given data to a new TermAlias instance and persists it.
     * Automatically clears the alias cache on success.
     *
     * @param  array  $data  Alias attributes. Example: ["alias" => "အိုရီယွန်", "canonical" => "Orion", "type" => "project"]
     * @return TermAlias The newly created alias instance
     *                   Example: TermAlias {id: "01J...", alias: "အိုရီယွန်", canonical: "Orion", type: "project", is_active: true}
     */
    public function create(array $data): TermAlias;

    /**
     * Update an existing term alias
     *
     * Finds the alias by ULID, applies the update, clears the cache, and
     * returns a fresh instance reflecting the persisted changes.
     *
     * @param  string  $id  The ULID of the alias to update. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @param  array  $data  Attributes to update. Example: ["canonical" => "NewOrion", "is_active" => false]
     * @return TermAlias The updated alias instance (fresh from database)
     *                   Example: TermAlias {id: "01J...", alias: "အိုရီယွန်", canonical: "NewOrion", ...}
     *
     * @throws ModelNotFoundException When no alias matches the ID
     *                                Example: update("nonexistent", []) → ModelNotFoundException
     */
    public function update(string $id, array $data): TermAlias;

    /**
     * Delete a term alias
     *
     * Finds the alias by ULID, deletes it, and clears the cache.
     * Throws ModelNotFoundException if not found.
     *
     * @param  string  $id  The ULID of the alias to delete. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     *
     * @throws ModelNotFoundException When no alias matches the ID
     *                                Example: delete("nonexistent") → ModelNotFoundException
     */
    public function delete(string $id): void;

    /**
     * Get all aliases as a flat mapping
     *
     * Returns a cached associative array of [alias_lowercase => canonical] for
     * all active aliases. The cache key is stored for 24 hours (86400s) and is
     * automatically cleared on create, update, or delete operations.
     * Entries where alias equals canonical are omitted as no-op.
     *
     * @return array<string, string> Alias map keyed by lowercase alias. Example: ["အိုရီယွန်" => "Orion", "or" => "Orion"]
     */
    public function getAliasMap(): array;

    /**
     * Expand a text by appending canonical terms
     *
     * Scans the input text for any known aliases (case-insensitive). For each
     * match found as a substring, the canonical term is appended to the output
     * unless the canonical term is already present in the text. Used for
     * plain-text query expansion before embedding search.
     *
     * @param  string  $text  The input text to expand. Example: "အိုရီယွန် project status"
     * @return string The expanded text with canonical terms appended. Example: "အိုရီယွန် project status Orion"
     */
    public function expandText(string $text): string;

    /**
     * Expand a FTS query string with OR canonical terms
     *
     * Splits the FTS query into individual terms. For each term that matches a
     * known alias (case-insensitive), the canonical term is appended as an OR
     * alternative. This allows PostgreSQL full-text search to match documents
     * containing either the alias or the canonical term.
     *
     * @param  string  $ftsQuery  The full-text search query string. Example: "အိုရီယွန် status"
     * @return string The expanded FTS query with OR terms. Example: "အိုရီယွန် Orion status"
     */
    public function expandFtsQuery(string $ftsQuery): string;

    /**
     * Clear the alias cache
     *
     * Removes the cached alias map from Redis (or the configured cache driver),
     * forcing the next call to getAliasMap() to re-fetch from the database.
     * Called automatically after create, update, and delete operations.
     */
    public function clearCache(): void;
}
