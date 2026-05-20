<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use Modules\VectorStoreModule\Models\VectorEmbedding;

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
 * @throws \RuntimeException On database connection failures or query errors
 */
class PgvectorDriver implements VectorStoreInterface
{
    /** @var DatabaseManager Laravel database manager for executing queries */
    private DatabaseManager $db;

    /** @var array<int> Supported vector dimension shard table sizes */
    private const DIM_TABLES = [384, 768, 1024, 1536, 3072];

    /** @var int Maximum supported vector dimensions */
    private const DIM_MAX = 3072;

    /** @var bool Whether the database driver is SQLite (triggers fallback) */
    private bool $isSqlite;

    /**
     * @param  DatabaseManager  $db  Laravel database manager. Example: app(DatabaseManager::class)
     */
    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
        $this->isSqlite = $db->getDriverName() === 'sqlite';
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
            return $this->searchSqlite($queryVector, $topK, $filters, $queryText);
        }

        $dimTable = $this->resolveDimTable(count($queryVector));
        $vectorLiteral = '['.implode(',', $queryVector).']';

        $vectorQuery = $this->db->table($dimTable, 've')
            ->select(
                've.id',
                've.chunk_id',
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
            )
            ->selectRaw('1 - (ve.embedding <=> ?::vector) as similarity_score', [$vectorLiteral])
            ->selectRaw('0.0 as fts_score')
            ->join('document_chunks as dc', 'dc.id', '=', 've.chunk_id')
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at')
            ->orderByRaw('ve.embedding <=> ?::vector', [$vectorLiteral])
            ->limit($topK * 3);

        $booleanFtsQuery = $filters['boolean_fts_query'] ?? null;
        unset($filters['boolean_fts_query']);

        if ($booleanFtsQuery !== null && $booleanFtsQuery !== '') {
            $sanitised = $this->sanitiseBooleanFts($booleanFtsQuery);
            $tsQuery = $this->db->table('document_chunks as dc')
                ->select(
                    'dc.id',
                    'dc.id as chunk_id',
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
                )
                ->selectRaw('0.0 as similarity_score')
                ->selectRaw('ts_rank(dc.tsv_content, to_tsquery(\'english\', ?)) as fts_score', [$sanitised])
                ->join('documents as d', 'd.id', '=', 'dc.document_id')
                ->whereNull('d.deleted_at')
                ->whereRaw('dc.tsv_content @@ to_tsquery(\'english\', ?)', [$sanitised])
                ->orderByRaw('ts_rank(dc.tsv_content, to_tsquery(\'english\', ?)) desc', [$sanitised])
                ->limit($topK * 3);
        } else {
            $tsQuery = $this->db->table('document_chunks as dc')
                ->select(
                    'dc.id',
                    'dc.id as chunk_id',
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
                )
                ->selectRaw('0.0 as similarity_score')
                ->selectRaw('ts_rank(dc.tsv_content, plainto_tsquery(\'english\', ?)) as fts_score', [$queryText])
                ->join('documents as d', 'd.id', '=', 'dc.document_id')
                ->whereNull('d.deleted_at')
                ->whereRaw('dc.tsv_content @@ plainto_tsquery(\'english\', ?)', [$queryText])
                ->orderByRaw('ts_rank(dc.tsv_content, plainto_tsquery(\'english\', ?)) desc', [$queryText])
                ->limit($topK * 3);
        }

        $vectorQuery = $this->applyFiltersVector($vectorQuery, $filters, 've');
        $tsQuery = $this->applyFiltersFts($tsQuery, $filters);

        $threshold = (float) ($filters['similarity_threshold'] ?? 0.65);

        $vectorResults = $vectorQuery->get()->toArray();
        $ftsResults = $tsQuery->get()->toArray();

        $vectorResults = array_values(
            array_filter($vectorResults, fn (object $row): bool => $row->similarity_score >= $threshold)
        );

        return $this->fuseResults($vectorResults, $ftsResults, $topK);
    }

    /**
     * Sanitise a boolean FTS query for PostgreSQL to_tsquery
     *
     * Strips characters that are not valid in a to_tsquery expression
     * (only letters, digits, spaces, &, |, !, (, ), and ' are allowed).
     * This prevents SQL syntax errors from user-generated boolean queries.
     *
     * @param string $query Raw boolean query. Example: "term1 & (term2 OR term3)"
     * @return string Sanitised query. Example: "term1 & (term2 OR term3)"
     */
    private function sanitiseBooleanFts(string $query): string
    {
        $sanitised = preg_replace('/[^a-zA-Z0-9\s&\|!()\'\x{1000}-\x{109F}]/u', ' ', $query);
        $sanitised = trim(preg_replace('/\s+/', ' ', $sanitised));

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

        $dimTable = $this->resolveDimTable(count($queryVector));
        $vectorLiteral = '['.implode(',', $queryVector).']';

        $query = $this->db->table($dimTable, 've')
            ->select(
                've.id',
                've.chunk_id',
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
            )
            ->selectRaw('1 - (ve.embedding <=> ?::vector) as similarity_score', [$vectorLiteral])
            ->join('document_chunks as dc', 'dc.id', '=', 've.chunk_id')
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at')
            ->orderByRaw('ve.embedding <=> ?::vector', [$vectorLiteral])
            ->limit($topK);

        $query = $this->applyFiltersVector($query, $filters, 've');

        $threshold = (float) ($filters['similarity_threshold'] ?? 0.65);
        $results = $query->get()->toArray();

        return array_values(
            array_filter($results, fn (object $row): bool => $row->similarity_score >= $threshold)
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
     * @param  string|null  $queryText  Unused in SQLite fallback. Example: null
     * @return array Result objects with similarity_score. Example: [["chunk_id" => "01J...", "similarity_score" => 0.92, "content" => "..."]]
     */
    private function searchSqlite(array $queryVector, int $topK, array $filters, ?string $queryText = null): array
    {
        $threshold = (float) ($filters['similarity_threshold'] ?? 0.65);

        $query = $this->db->table('vector_embeddings as ve')
            ->select(
                've.id',
                've.chunk_id',
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
            )
            ->join('document_chunks as dc', 'dc.id', '=', 've.chunk_id')
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at')
            ->limit($topK);

        $query = $this->applyFiltersVector($query, $filters, 've');

        $results = $query->get()->toArray();
        $results = array_map(function (object $row) use ($queryVector): object {
            $embedding = $row->embedding ?? '[]';
            $stored = is_string($embedding) ? json_decode($embedding, true) : (array) $embedding;
            $row->similarity_score = $this->cosineSimilarity($queryVector, $stored);

            return $row;
        }, $results);

        usort($results, fn (object $a, object $b): int => $b->similarity_score <=> $a->similarity_score);
        $results = array_values(
            array_filter($results, fn (object $row): bool => $row->similarity_score >= $threshold)
        );

        return $results;
    }

    /**
     * Delete vectors by their ULIDs.
     *
     * Removes records from both the dimension shard tables (PostgreSQL only)
     * and the vector_embeddings metadata table. Shard table deletions are
     * wrapped in try/catch since the table may not exist for all dimensions.
     *
     * @param  array  $ids  Array of vector ULIDs to delete. Example: ["01JAR...", "01JAS..."]
     *
     * @throws \RuntimeException When the vector_embeddings delete fails
     */
    public function delete(array $ids): void
    {
        if (! $this->isSqlite) {
            foreach (self::DIM_TABLES as $dim) {
                try {
                    $this->db->table("ve_{$dim}")->whereIn('id', $ids)->delete();
                } catch (\RuntimeException) {
                    // Shard table may not exist — skip.
                }
            }
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

        if (! $this->isSqlite) {
            foreach (self::DIM_TABLES as $dim) {
                try {
                    $this->db->table("ve_{$dim}")
                        ->whereIn('chunk_id', $chunkIds)
                        ->delete();
                } catch (\RuntimeException) {
                    // Shard table may not exist — skip.
                }
            }
        }

        $this->db->table('vector_embeddings')
            ->whereIn('chunk_id', $chunkIds)
            ->delete();
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
     * Apply metadata filters to a vector search query builder.
     *
     * Supports filters: document_ids, date_from, date_to, user_ids, project,
     * report_date_from, report_date_to, model_name.
     *
     * @param  mixed  $query  Query builder instance. Example: DB::table('ve_1536 as ve')
     * @param  array  $filters  Filter conditions. Example: ["project" => "Orion", "user_ids" => ["01J..."]]
     * @param  string  $alias  Table alias used in the query. Example: "ve"
     * @return mixed Query builder with WHERE clauses applied
     */
    private function applyFiltersVector($query, array $filters, string $alias): mixed
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
        if (isset($filters['model_name'])) {
            $query->where("{$alias}.model_name", $filters['model_name']);
        }
        if (isset($filters['meta']) && is_array($filters['meta'])) {
            if (! $this->isSqlite) {
                foreach ($filters['meta'] as $key => $value) {
                    $jsonCondition = json_encode([$key => $value]);
                    $query->whereRaw('dc.metadata @> ?::jsonb', [$jsonCondition]);
                }
            } elseif ($this->isSqlite) {
                foreach ($filters['meta'] as $key => $value) {
                    $query->whereRaw("json_extract(dc.metadata, '$.\"{$key}\"') = ?", [$value]);
                }
            }
        }

        return $query;
    }

    /**
     * Apply metadata filters to a full-text search query builder.
     *
     * Supports filters: document_ids, date_from, date_to, user_ids, project,
     * report_date_from, report_date_to. Note: model_name filter is not
     * supported in FTS queries (handled by vector query only).
     *
     * @param  mixed  $query  Query builder instance. Example: DB::table('document_chunks as dc')
     * @param  array  $filters  Filter conditions. Example: ["project" => "Orion"]
     * @return mixed Query builder with WHERE clauses applied
     */
    private function applyFiltersFts($query, array $filters): mixed
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
        if (isset($filters['meta']) && is_array($filters['meta'])) {
            if (! $this->isSqlite) {
                foreach ($filters['meta'] as $key => $value) {
                    $jsonCondition = json_encode([$key => $value]);
                    $query->whereRaw('dc.metadata @> ?::jsonb', [$jsonCondition]);
                }
            } elseif ($this->isSqlite) {
                foreach ($filters['meta'] as $key => $value) {
                    $query->whereRaw("json_extract(dc.metadata, '$.\"{$key}\"') = ?", [$value]);
                }
            }
        }

        return $query;
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
        $k = 60;
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
     * Compute the cosine similarity between two vectors.
     *
     * Calculates dot product divided by the product of Euclidean norms.
     * Returns 0.0 if either vector has zero magnitude.
     *
     * @param  array  $a  First float vector. Example: [0.1, 0.2, 0.3]
     * @param  array  $b  Second float vector. Example: [0.4, 0.5, 0.6]
     * @return float Cosine similarity between -1 and 1. Example: 0.975
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / ($normA * $normB);
    }
}
