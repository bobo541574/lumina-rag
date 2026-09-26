<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Services;

use App\Support\VectorMath;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use Modules\VectorStoreModule\Models\VectorEmbedding;
use Modules\VectorStoreModule\Support\VectorShardSchema;

/**
 * pgvector Vector Store Driver
 *
 * Implements VectorStoreInterface using PostgreSQL's pgvector extension.
 * Stores vectors in dimension-based shard tables (ve_{384,768,1024,1536,3072})
 * with IVFFlat indexes for efficient cosine similarity search. Metadata-only
 * records are kept in the vector_embeddings table for cross-shard operations.
 *
 * Supports pure vector search and hybrid search (vector + full-text via
 * plainto_tsquery on the document_chunks.tsv_content column). Results from
 * both search strategies are fused using Reciprocal Rank Fusion (RRF).
 *
 * Falls back to SQLite-compatible in-memory cosine similarity when the
 * database driver is SQLite (used during testing).
 *
 * @param  DatabaseManager  $db  Laravel database manager. Example: app(DatabaseManager::class)
 *
 * Metadata filter keys are restricted to an allow-list so client-supplied keys
 * can never be interpolated into raw SQL (injection defence).
 *
 * @throws \RuntimeException On database connection failures or query errors
 */
class PgvectorDriver implements VectorStoreInterface
{
    /**
     * Metadata keys that may be used as search filters when clients pass a
     * `meta` filter. Any other key is silently dropped.
     */
    public const ALLOWED_META_KEYS = [
        'section',
        'quarter',
        'project',
        'user_name',
        'report_date',
        'document_title',
        'page_number',
    ];

    /** @var DatabaseManager Laravel database manager for executing queries */
    private DatabaseManager $db;

    /** @var array<int> Supported vector dimension shard table sizes */
    private const DIM_TABLES = VectorShardSchema::DIMENSIONS;

    /** @var float Similarity cut-off when the caller passes none */
    private const DEFAULT_THRESHOLD = 0.65;

    /** @var array<string> Chunk/document columns returned by every search */
    private const RESULT_COLUMNS = [
        'dc.content',
        'dc.metadata',
        'd.id as document_id',
        'd.title as document_title',
        'd.user_id as document_user_id',
        'd.project as document_project',
        'd.report_date as document_report_date',
        'dc.chunk_index',
        'dc.page_number',
        'd.created_at as document_created_at',
    ];

    /** @var int Maximum supported vector dimensions */
    private const DIM_MAX = 3072;

    /** @var bool Whether the database driver is SQLite (triggers fallback) */
    private bool $isSqlite;

    /** @var array<string>|null Memoized list of existing shard tables */
    private ?array $existingShardTables = null;

    /** @var int Reciprocal Rank Fusion rank constant */
    private int $rrfK;

    /**
     * @param  DatabaseManager  $db  Laravel database manager. Example: app(DatabaseManager::class)
     * @param  int  $rrfK  RRF rank constant (k in 1/(k+rank)). Example: 60
     */
    public function __construct(DatabaseManager $db, int $rrfK = 60)
    {
        $this->db = $db;
        $this->isSqlite = $db->getDriverName() === 'sqlite';
        $this->rrfK = $rrfK;
    }

    /**
     * Upsert vectors into the vector store.
     *
     * Inserts a metadata record into the vector_embeddings table and, when
     * not using SQLite, stores the actual vector in the appropriate dimension
     * shard table. Each vector gets a new ULID.
     *
     * @param  array  $vectors  Array of float vectors. Example: [[0.012, -0.034, ...], [0.023, 0.045, ...]]
     * @param  array  $metadata  Per-vector metadata (model_name, content_hash). Example: [["model_name" => "text-embedding-ada-002", "content_hash" => "a1b2c3"], ...]
     * @param  string|array  $chunkId  Chunk ULID(s) associated with these vectors. Example: "01J..." or ["01J...", "01J..."]
     * @param  string  $namespace  Namespace/scope (currently unused but reserved). Example: "default"
     * @return array Array of newly created vector ULIDs. Example: ["01JAR...", "01JAS...", "01JAT..."]
     *
     * @throws \RuntimeException When the database insert fails
     * @throws \InvalidArgumentException When vector dimensions exceed DIM_MAX
     */
    public function upsert(array $vectors, array $metadata, string|array $chunkId, string $namespace): array
    {
        $chunkIds = is_array($chunkId) ? $chunkId : array_fill(0, count($vectors), $chunkId);
        $now = now()->toDateTimeString();
        $ids = [];

        foreach ($vectors as $i => $vector) {
            $meta = isset($metadata[$i]) ? $metadata[$i] : $metadata;
            $id = (string) Str::ulid();
            $ids[] = $id;
            $dim = count($vector);
            $cid = $chunkIds[$i] ?? $chunkIds[0];
            $modelName = $meta['model_name'] ?? 'unknown';
            $contentHash = $meta['content_hash'] ?? md5((string) $i);

            if ($this->isSqlite) {
                $this->db->statement(
                    'INSERT INTO vector_embeddings (id, chunk_id, embedding, dimensions, model_name, content_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $cid, json_encode($vector), $dim, $modelName, $contentHash, $now]
                );

                continue;
            }

            $this->db->statement(
                'INSERT INTO vector_embeddings (id, chunk_id, dimensions, model_name, content_hash, created_at) VALUES (?, ?, ?, ?, ?, ?::timestamptz)',
                [$id, $cid, $dim, $modelName, $contentHash, $now]
            );

            $dimTable = $this->resolveDimTable($dim);
            $vectorLiteral = '['.implode(',', $vector).']';

            $this->db->statement(
                "INSERT INTO {$dimTable} (id, chunk_id, embedding, model_name, content_hash, created_at) VALUES (?, ?, ?::vector, ?, ?, ?::timestamptz)",
                [$id, $cid, $vectorLiteral, $modelName, $contentHash, $now]
            );
        }

        return $ids;
    }

    /**
     * Perform a hybrid search combining vector cosine similarity and full-text search.
     *
     * Runs vector search (cosine distance via <=> operator on the shard table)
     * and full-text search (plainto_tsquery on document_chunks.tsv_content) in
     * parallel, then fuses results using Reciprocal Rank Fusion (RRF). Results
     * below the similarity_threshold filter are excluded.
     *
     * On SQLite, falls back to pure vector search with in-memory cosine similarity.
     *
     * @param  string  $queryText  Raw query text for FTS. Example: "What is Project Orion?"
     * @param  array  $queryVector  Query vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  int  $topK  Number of results to return. Example: 5
     * @param  array  $filters  Metadata filters. Example: ["project" => "Orion", "similarity_threshold" => 0.65]
     * @return array Fused result objects. Example: [["chunk_id" => "01J...", "similarity_score" => 0.87, "content" => "...", "document_title" => "Report.pdf"]]
     *
     * @throws \RuntimeException When a database query fails
     */
    public function searchHybrid(string $queryText, array $queryVector, int $topK = 5, array $filters = []): array
    {
        if ($this->isSqlite) {
            return $this->searchSqlite($queryVector, $topK, $filters);
        }

        $booleanFtsQuery = $this->sanitiseBooleanFts((string) ($filters['boolean_fts_query'] ?? ''));
        unset($filters['boolean_fts_query']);
        $threshold = (float) ($filters['similarity_threshold'] ?? self::DEFAULT_THRESHOLD);

        $vectorResults = $this->aboveThreshold(
            $this->applyFilters($this->vectorQuery($queryVector, $topK * 3)->selectRaw('0.0 as fts_score'), $filters, 've')->get()->all(),
            $threshold,
        );

        // Prefer the rewriter's boolean to_tsquery; fall back to plainto_tsquery
        // on the raw text; skip FTS when there is no meaningful text at all.
        $ftsResults = match (true) {
            $booleanFtsQuery !== '' => $this->ftsQuery('to_tsquery', $booleanFtsQuery, $topK * 3, $filters)->get()->all(),
            $queryText !== '' => $this->ftsQuery('plainto_tsquery', $queryText, $topK * 3, $filters)->get()->all(),
            default => [],
        };

        return $this->fuseResults($vectorResults, $ftsResults, $topK);
    }

    /**
     * Sanitise a boolean FTS query for PostgreSQL to_tsquery
     *
     * Strips characters that are not valid in a to_tsquery expression
     * (only letters, digits, spaces, &, |, !, (, ), and ' are allowed).
     * This prevents SQL syntax errors from user-generated boolean queries.
     *
     * @param  string  $query  Raw boolean query. Example: "term1 & (term2 OR term3)"
     * @return string Sanitised query. Example: "term1 & (term2 OR term3)"
     */
    private function sanitiseBooleanFts(string $query): string
    {
        // Keep only valid tsquery characters
        $sanitised = preg_replace('/[^a-zA-Z0-9\s&\|!()\'\x{1000}-\x{109F}]/u', ' ', $query);
        $sanitised = trim(preg_replace('/\s+/', ' ', $sanitised));

        // Insert & between adjacent word tokens separated only by whitespace.
        // This prevents tsquery syntax errors when sanitisation replaces
        // special characters (e.g. hyphens in "2026-04-30") with spaces,
        // producing "2026 04 30" which PostgreSQL cannot parse.
        $sanitised = preg_replace(
            '/([a-zA-Z0-9\x{1000}-\x{109F}])\s+(?=[a-zA-Z0-9\x{1000}-\x{109F}])/u',
            '$1 & ',
            $sanitised,
        );

        if ($sanitised === '' || $sanitised === '()') {
            return '';
        }

        return $sanitised;
    }

    /**
     * Perform a pure vector similarity search.
     *
     * Searches the dimension shard table using cosine distance (<=> operator)
     * with an IVFFlat index. Joins with document_chunks and documents to
     * return rich result objects. Applies similarity_threshold filtering
     * and optional metadata filters.
     *
     * On SQLite, falls back to in-memory cosine similarity computation.
     *
     * @param  array  $queryVector  Query vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  int  $topK  Number of results to return. Example: 5
     * @param  array  $filters  Metadata filters. Example: ["project" => "Orion", "user_ids" => ["01J..."]]
     * @return array Result objects with similarity_score. Example: [["chunk_id" => "01J...", "similarity_score" => 0.92, "content" => "...", "document_title" => "Report.pdf"]]
     *
     * @throws \RuntimeException When a database query fails
     */
    public function search(array $queryVector, int $topK = 5, array $filters = []): array
    {
        if ($this->isSqlite) {
            return $this->searchSqlite($queryVector, $topK, $filters);
        }

        return $this->aboveThreshold(
            $this->applyFilters($this->vectorQuery($queryVector, $topK), $filters, 've')->get()->all(),
            (float) ($filters['similarity_threshold'] ?? self::DEFAULT_THRESHOLD),
        );
    }

    /**
     * Perform vector search using SQLite's in-memory cosine similarity.
     *
     * Fallback path used when the database driver is SQLite (testing).
     * Loads all vector_embeddings records, computes cosine similarity in
     * PHP, sorts, filters by threshold, and returns top K results.
     *
     * @param  array  $queryVector  Query vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  int  $topK  Number of results to return. Example: 5
     * @param  array  $filters  Metadata filters. Example: ["project" => "Orion"]
     * @return array Result objects with similarity_score. Example: [["chunk_id" => "01J...", "similarity_score" => 0.92, "content" => "..."]]
     */
    private function searchSqlite(array $queryVector, int $topK, array $filters): array
    {
        $query = $this->chunkColumns($this->db->table('vector_embeddings as ve')->select('ve.id', 've.chunk_id', 've.embedding'))
            ->join('document_chunks as dc', 'dc.id', '=', 've.chunk_id')
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at');

        $results = array_map(function (object $row) use ($queryVector): object {
            $stored = is_string($row->embedding) ? json_decode($row->embedding, true) : (array) ($row->embedding ?? []);
            $row->similarity_score = VectorMath::cosine($queryVector, $stored ?? []);
            unset($row->embedding);

            return $row;
        }, $this->applyFilters($query, $filters, 've')->get()->all());

        usort($results, fn (object $a, object $b): int => $b->similarity_score <=> $a->similarity_score);

        // Score in PHP first, then cut to top K — a SQL LIMIT would drop
        // rows before they were ranked.
        return array_slice(
            $this->aboveThreshold($results, (float) ($filters['similarity_threshold'] ?? self::DEFAULT_THRESHOLD)),
            0,
            $topK,
        );
    }

    /**
     * Delete vectors by their ULIDs.
     *
     * Removes records from both the dimension shard tables (PostgreSQL only)
     * and the vector_embeddings metadata table.
     *
     * @param  array  $ids  Array of vector ULIDs to delete. Example: ["01JAR...", "01JAS..."]
     *
     * @throws \RuntimeException When the vector_embeddings delete fails
     */
    public function delete(array $ids): void
    {
        foreach ($this->shardTables() as $table) {
            $this->db->table($table)->whereIn('id', $ids)->delete();
        }
        VectorEmbedding::whereIn('id', $ids)->delete();
    }

    /**
     * Delete all vectors belonging to a document.
     *
     * Finds all chunk ULIDs for the given document, then removes vectors
     * from both the dimension shard tables (PostgreSQL only) and the
     * vector_embeddings metadata table. No-op if the document has no chunks.
     *
     * @param  string  $documentId  Document ULID. Example: "01JAR..."
     *
     * @throws \RuntimeException When the database delete query fails
     */
    public function deleteByDocumentId(string $documentId): void
    {
        $chunkIds = $this->db->table('document_chunks')
            ->where('document_id', $documentId)
            ->pluck('id');

        if ($chunkIds->isEmpty()) {
            return;
        }

        foreach ($this->shardTables() as $table) {
            $this->db->table($table)->whereIn('chunk_id', $chunkIds)->delete();
        }

        $this->db->table('vector_embeddings')
            ->whereIn('chunk_id', $chunkIds)
            ->delete();
    }

    /**
     * Count stored vectors for the given chunks.
     *
     * On PostgreSQL the vectors live in the shard tables; on SQLite in
     * vector_embeddings.
     *
     * @param  array<string>  $chunkIds  Chunk ULIDs. Example: ["01JAR...", "01JAS..."]
     * @return int Number of stored vectors. Example: 42
     */
    public function countByChunkIds(array $chunkIds): int
    {
        if ($chunkIds === []) {
            return 0;
        }

        if ($this->isSqlite) {
            return $this->db->table('vector_embeddings')->whereIn('chunk_id', $chunkIds)->count();
        }

        $count = 0;
        foreach ($this->shardTables() as $table) {
            $count += $this->db->table($table)->whereIn('chunk_id', $chunkIds)->count();
        }

        return $count;
    }

    /**
     * Shard tables that exist on this installation (none on SQLite).
     *
     * Checked up front instead of catching errors per table: on PostgreSQL a
     * failed statement aborts the surrounding transaction, so a missing shard
     * table must never be queried.
     *
     * @return array<string> Existing shard table names. Example: ["ve_768", "ve_1536"]
     */
    private function shardTables(): array
    {
        if ($this->isSqlite) {
            return [];
        }

        return $this->existingShardTables ??= array_values(array_filter(
            array_map(fn (int $dim): string => "ve_{$dim}", self::DIM_TABLES),
            fn (string $table): bool => $this->db->connection()->getSchemaBuilder()->hasTable($table),
        ));
    }

    /**
     * Get vector store statistics.
     *
     * Queries the vector_embeddings table for total count, count grouped
     * by dimensions, and count grouped by model name.
     *
     * @return array Stats with total_vectors, by_dimensions, by_model. Example: ["total_vectors" => 1500, "by_dimensions" => [768 => 500, 1536 => 1000], "by_model" => ["text-embedding-ada-002" => 1000, "nomic-embed-text" => 500]]
     */
    public function getStats(): array
    {
        $count = VectorEmbedding::count();
        $dimCounts = VectorEmbedding::selectRaw('dimensions, count(*) as count')
            ->groupBy('dimensions')
            ->pluck('count', 'dimensions')
            ->toArray();
        $modelCounts = VectorEmbedding::selectRaw('model_name, count(*) as count')
            ->groupBy('model_name')
            ->pluck('count', 'model_name')
            ->toArray();

        return [
            'total_vectors' => $count,
            'by_dimensions' => $dimCounts,
            'by_model' => $modelCounts,
        ];
    }

    /**
     * Resolve the nearest dimension shard table name for a given dimensionality.
     *
     * Finds the closest entry in DIM_TABLES to the requested dimensions and
     * returns the corresponding table name (e.g., "ve_1536").
     *
     * @param  int  $dimensions  Vector dimensionality. Example: 1536
     * @return string Shard table name. Example: "ve_1536"
     */
    private function resolveDimTable(int $dimensions): string
    {
        $nearest = self::DIM_TABLES[0];
        foreach (self::DIM_TABLES as $dim) {
            if (abs($dim - $dimensions) < abs($nearest - $dimensions)) {
                $nearest = $dim;
            }
        }

        return "ve_{$nearest}";
    }

    /**
     * Apply search filters to a vector or FTS query builder.
     *
     * Supports filters: document_ids, date_from, date_to, user_ids, project,
     * report_date_from, report_date_to, meta, and model_name (vector queries only).
     *
     * @param  Builder  $query  Query joining `dc` (chunks) and `d` (documents). Example: DB::table('ve_1536 as ve')
     * @param  array  $filters  Filter conditions. Example: ["project" => "Orion", "user_ids" => ["01J..."]]
     * @param  string|null  $alias  Vector table alias for the model filter; null for FTS queries. Example: "ve"
     * @return Builder Query builder with WHERE clauses applied
     */
    private function applyFilters(Builder $query, array $filters, ?string $alias = null): Builder
    {
        if (isset($filters['document_ids'])) {
            $query->whereIn('d.id', (array) $filters['document_ids']);
        }
        if (isset($filters['date_from'])) {
            $query->where('d.created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('d.created_at', '<=', $filters['date_to']);
        }
        if (isset($filters['user_ids'])) {
            $query->whereIn('d.user_id', (array) $filters['user_ids']);
        }
        if (isset($filters['project'])) {
            $query->where('d.project', $filters['project']);
        }
        if (isset($filters['report_date_from'])) {
            $query->where('d.report_date', '>=', $filters['report_date_from']);
        }
        if (isset($filters['report_date_to'])) {
            $query->where('d.report_date', '<=', $filters['report_date_to']);
        }
        // Only vector queries carry a model; FTS rows are model-independent.
        if ($alias !== null && isset($filters['model_name'])) {
            $query->where("{$alias}.model_name", $filters['model_name']);
        }
        if (isset($filters['meta']) && is_array($filters['meta'])) {
            $meta = $this->whitelistMetaKeys($filters['meta']);
            if ($meta !== []) {
                if (! $this->isSqlite) {
                    foreach ($meta as $key => $value) {
                        $jsonCondition = json_encode([$key => $value]);
                        $query->whereRaw('dc.metadata @> ?::jsonb', [$jsonCondition]);
                    }
                } else {
                    foreach ($meta as $key => $value) {
                        $query->whereRaw("json_extract(dc.metadata, '$.\"{$key}\"') = ?", [$value]);
                    }
                }
            }
        }

        return $query;
    }

    /**
     * Keep only metadata keys that are safe to use as search filters.
     *
     * Client-supplied meta filters are narrowed to the allow-list so unknown
     * or malicious keys are never interpolated into raw SQL.
     *
     * @param  array  $meta  Raw metadata filters. Example: ["section" => "Revenue", "evil" => "x"]
     * @return array Filtered metadata keyed by allow-listed key only. Example: ["section" => "Revenue"]
     */
    private function whitelistMetaKeys(array $meta): array
    {
        return array_intersect_key($meta, array_flip(self::ALLOWED_META_KEYS));
    }

    /**
     * Fuse vector and FTS results using Reciprocal Rank Fusion (RRF).
     *
     * Each result from both sets is scored as 1/(k + rank) where k is a
     * constant (default 60). Scores are summed for chunks that appear in
     * both result sets. Final scores are normalised to 0-1 range so
     * downstream threshold filtering (designed for cosine similarity) works.
     *
     * @param  array  $vectorResults  Results from vector search. Example: [["chunk_id" => "01J...", "similarity_score" => 0.9], ...]
     * @param  array  $ftsResults  Results from full-text search. Example: [["chunk_id" => "01J...", "fts_score" => 0.8], ...]
     * @param  int  $topK  Number of results to return after fusion. Example: 5
     * @return array Fused results sorted by RRF score, limited to topK. Example: [["chunk_id" => "01J...", "similarity_score" => 1.0, "content" => "..."]]
     */
    private function fuseResults(array $vectorResults, array $ftsResults, int $topK): array
    {
        $k = $this->rrfK;
        $scores = [];

        foreach ($vectorResults as $i => $row) {
            $key = $row->chunk_id;
            $scores[$key] = [
                'row' => $row,
                'score' => 1.0 / ($k + $i + 1),
            ];
        }

        foreach ($ftsResults as $i => $row) {
            $key = $row->chunk_id;
            if (isset($scores[$key])) {
                $scores[$key]['score'] += 1.0 / ($k + $i + 1);
            } else {
                $scores[$key] = [
                    'row' => $row,
                    'score' => 1.0 / ($k + $i + 1),
                ];
            }
        }

        usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // Normalise RRF scores to 0-1 so downstream threshold logic
        // (designed for cosine similarity) works correctly.
        $topItems = array_slice($scores, 0, $topK);
        $maxScore = $topItems !== [] ? $topItems[0]['score'] : 1.0;

        $results = [];
        foreach ($topItems as $item) {
            $row = $item['row'];
            $row->similarity_score = $maxScore > 0.0 ? $item['score'] / $maxScore : 0.0;
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Nearest-neighbour query against the shard table matching the vector's dimensions.
     *
     * @param  array<float>  $queryVector  Query embedding. Example: [0.012, -0.034, ...]
     * @param  int  $limit  Rows to fetch. Example: 15
     */
    private function vectorQuery(array $queryVector, int $limit): Builder
    {
        $vectorLiteral = '['.implode(',', $queryVector).']';

        return $this->chunkColumns($this->db->table($this->resolveDimTable(count($queryVector)), 've')->select('ve.id', 've.chunk_id'))
            ->selectRaw('1 - (ve.embedding <=> ?::vector) as similarity_score', [$vectorLiteral])
            ->join('document_chunks as dc', 'dc.id', '=', 've.chunk_id')
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at')
            ->orderByRaw('ve.embedding <=> ?::vector', [$vectorLiteral])
            ->limit($limit);
    }

    /**
     * Full-text query ranked by ts_rank.
     *
     * @param  string  $function  "to_tsquery" (boolean syntax) or "plainto_tsquery". Example: "to_tsquery"
     * @param  string  $terms  Query terms. Example: "revenue & q3"
     * @param  int  $limit  Rows to fetch. Example: 15
     * @param  array  $filters  Search filters
     */
    private function ftsQuery(string $function, string $terms, int $limit, array $filters): Builder
    {
        $tsQuery = "{$function}('english', ?)";

        $query = $this->chunkColumns($this->db->table('document_chunks as dc')->select('dc.id', 'dc.id as chunk_id'))
            ->selectRaw('0.0 as similarity_score')
            ->selectRaw("ts_rank(dc.tsv_content, {$tsQuery}) as fts_score", [$terms])
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at')
            ->whereRaw("dc.tsv_content @@ {$tsQuery}", [$terms])
            ->orderByRaw("ts_rank(dc.tsv_content, {$tsQuery}) desc", [$terms])
            ->limit($limit);

        return $this->applyFilters($query, $filters);
    }

    /**
     * Add the chunk/document columns every search result carries.
     */
    private function chunkColumns(Builder $query): Builder
    {
        return $query->addSelect(self::RESULT_COLUMNS);
    }

    /**
     * @param  array<object>  $rows  Rows with similarity_score
     * @param  float  $threshold  Minimum similarity. Example: 0.65
     * @return array<object> Rows at or above the threshold, reindexed
     */
    private function aboveThreshold(array $rows, float $threshold): array
    {
        return array_values(array_filter($rows, fn (object $row): bool => $row->similarity_score >= $threshold));
    }
}
