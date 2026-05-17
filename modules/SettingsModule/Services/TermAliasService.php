<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\SettingsModule\Models\TermAlias;

/**
 * Term Alias Service
 *
 * Concrete implementation of TermAliasServiceInterface providing CRUD operations
 * and search expansion utilities for term aliases. Aliases allow the RAG pipeline
 * to recognize non-canonical terms (Burmese translations, abbreviations, etc.)
 * during search by expanding both plain text and FTS queries.
 *
 * The alias map is cached via Laravel's CacheRepository (Redis-backed) with a
 * 24-hour TTL. Cache is automatically invalidated on create, update, and delete
 * operations to maintain consistency without requiring manual intervention.
 *
 * @implements TermAliasServiceInterface
 */
class TermAliasService implements TermAliasServiceInterface
{
    /**
     * Cache key for the alias map stored in Redis.
     */
    private const CACHE_KEY = 'term_aliases:map';

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache  Laravel cache store (Redis recommended). Example: $app->make(CacheRepository::class)
     */
    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    /**
     * List all aliases with optional type filter and pagination
     *
     * Queries the TermAlias table ordered by type then alias. When type is
     * provided, filters by that type. Supports optional pagination with
     * standard Laravel pagination meta in the response.
     *
     * @param  string|null  $type  Filter by alias type: "project", "technical", or "general". Example: "project"
     * @param  int|null  $page  Page number (1-based). Example: 1
     * @param  int|null  $perPage  Items per page. Example: 20
     * @return array{data: array, meta: array|null} List of aliases with optional pagination meta
     *                                              Example: ["data" => [["id" => "01J...", "alias" => "အိုရီယွန်", "canonical" => "Orion", ...]], "meta" => ["current_page" => 1, "last_page" => 3, "total" => 50]]
     */
    public function getAll(?string $type = null, ?int $page = null, ?int $perPage = null): array
    {
        $query = TermAlias::orderBy('type')->orderBy('alias');

        if ($type !== null) {
            $query->where('type', $type);
        }

        if ($page !== null && $perPage !== null) {
            $result = $query->paginate($perPage, ['*'], 'page', $page)->toArray();

            return [
                'data' => $result['data'],
                'meta' => [
                    'current_page' => $result['current_page'],
                    'last_page' => $result['last_page'],
                    'per_page' => $result['per_page'],
                    'total' => $result['total'],
                    'from' => $result['from'],
                    'to' => $result['to'],
                ],
            ];
        }

        return [
            'data' => $query->get()->toArray(),
            'meta' => null,
        ];
    }

    /**
     * Find a term alias by its ULID
     *
     * Delegates to Eloquent's findOrFail. Throws ModelNotFoundException when
     * no record matches the given ULID.
     *
     * @param  string  $id  The ULID of the alias. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @return TermAlias The found alias instance
     *                   Example: TermAlias {id: "01J...", alias: "အိုရီယွန်", canonical: "Orion", type: "project"}
     *
     * @throws ModelNotFoundException When no alias matches the ID
     *                                Example: find("nonexistent") → ModelNotFoundException
     */
    public function find(string $id): TermAlias
    {
        return TermAlias::findOrFail($id);
    }

    /**
     * Create a new term alias
     *
     * Persists a new TermAlias with the given data and immediately clears the
     * alias cache so subsequent searches reflect the new mapping.
     *
     * @param  array  $data  Alias attributes. Example: ["alias" => "အိုရီယွန်", "canonical" => "Orion", "type" => "project"]
     * @return TermAlias The newly created alias instance
     *                   Example: TermAlias {id: "01J...", alias: "အိုရီယွန်", canonical: "Orion", type: "project", is_active: true}
     */
    public function create(array $data): TermAlias
    {
        $alias = TermAlias::create($data);
        $this->clearCache();

        return $alias;
    }

    /**
     * Update an existing term alias
     *
     * Finds the alias by ULID, applies the update, clears the cache, and
     * returns a fresh instance. The fresh() call ensures cast attributes
     * (e.g., is_active boolean) are properly hydrated from the database.
     *
     * @param  string  $id  The ULID of the alias to update. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     * @param  array  $data  Attributes to update. Example: ["canonical" => "NewOrion", "is_active" => false]
     * @return TermAlias The updated alias instance (fresh from database)
     *                   Example: TermAlias {id: "01J...", alias: "အိုရီယွန်", canonical: "NewOrion", ...}
     *
     * @throws ModelNotFoundException When no alias matches the ID
     *                                Example: update("nonexistent", []) → ModelNotFoundException
     */
    public function update(string $id, array $data): TermAlias
    {
        $alias = $this->find($id);
        $alias->update($data);
        $this->clearCache();

        return $alias->fresh();
    }

    /**
     * Delete a term alias
     *
     * Finds the alias by ULID, deletes it, and clears the cache to ensure
     * stale mappings are not used in subsequent searches.
     *
     * @param  string  $id  The ULID of the alias to delete. Example: "01JARQ3Z7KZXJ6Y6Y6Y6Y6Y6Y6"
     *
     * @throws ModelNotFoundException When no alias matches the ID
     *                                Example: delete("nonexistent") → ModelNotFoundException
     */
    public function delete(string $id): void
    {
        $alias = $this->find($id);
        $alias->delete();
        $this->clearCache();
    }

    /**
     * Get all aliases as a flat mapping
     *
     * Returns a cached associative array of [alias_lowercase => canonical] for
     * all active aliases. Cache is stored for 24 hours and automatically cleared
     * on data mutations. Entries where alias equals canonical are excluded.
     * Uses mb_strtolower for case-insensitive matching across Unicode text.
     *
     * @return array<string, string> Alias map keyed by lowercase alias. Example: ["အိုရီယွန်" => "Orion", "or" => "Orion"]
     */
    public function getAliasMap(): array
    {
        return $this->cache->remember(self::CACHE_KEY, 86400, function (): array {
            $aliases = TermAlias::active()->get(['alias', 'canonical']);

            $map = [];
            foreach ($aliases as $row) {
                $aliasLower = mb_strtolower($row->alias);
                $canonical = $row->canonical;
                if ($aliasLower !== mb_strtolower($canonical)) {
                    $map[$aliasLower] = $canonical;
                }
            }

            return $map;
        });
    }

    /**
     * Expand a text by appending canonical terms
     *
     * Iterates over all alias entries and appends the canonical term to the
     * input text if the alias is found as a substring (case-insensitive) and
     * the canonical term is not already present. This enables plain-text query
     * expansion before embedding vector search, allowing documents to be
     * matched even when the user uses a non-canonical term.
     *
     * @param  string  $text  The input text to expand. Example: "အိုရီယွန် project status"
     * @return string The expanded text with canonical terms appended. Example: "အိုရီယွန် project status Orion"
     */
    public function expandText(string $text): string
    {
        $map = $this->getAliasMap();
        if ($map === []) {
            return $text;
        }

        $lower = mb_strtolower($text);
        $expanded = $text;

        foreach ($map as $alias => $canonical) {
            if (str_contains($lower, $alias)) {
                if (! str_contains($lower, mb_strtolower($canonical))) {
                    $expanded .= " {$canonical}";
                }
            }
        }

        return $expanded;
    }

    /**
     * Expand a FTS query string with OR canonical terms
     *
     * Splits the FTS query into whitespace-delimited terms. For each term
     * matching a known alias (case-insensitive), appends the canonical term
     * as an additional search term. This allows PostgreSQL plainto_tsquery
     * to match documents containing either the alias or the canonical form,
     * enabling multilingual and variant-aware full-text search.
     *
     * @param  string  $ftsQuery  The full-text search query string. Example: "အိုရီယွန် status"
     * @return string The expanded FTS query with canonical terms appended. Example: "အိုရီယွန် Orion status"
     */
    public function expandFtsQuery(string $ftsQuery): string
    {
        $map = $this->getAliasMap();
        if ($map === [] || trim($ftsQuery) === '') {
            return $ftsQuery;
        }

        $terms = preg_split('/\s+/', $ftsQuery);
        if ($terms === false || $terms === []) {
            return $ftsQuery;
        }

        $expanded = [];
        foreach ($terms as $term) {
            $termLower = mb_strtolower($term);
            $expanded[] = $term;

            if (isset($map[$termLower])) {
                $expanded[] = $map[$termLower];
            }
        }

        return implode(' ', $expanded);
    }

    /**
     * Clear the alias cache
     *
     * Removes the cached alias map from the cache store, forcing a fresh
     * database query on the next call to getAliasMap(). Called automatically
     * by create, update, and delete to keep the cache in sync with the DB.
     */
    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
