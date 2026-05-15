<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use Modules\VectorStoreModule\Models\VectorEmbedding;

class PgvectorDriver implements VectorStoreInterface
{
    private DatabaseManager $db;

    private const DIM_TABLES = [384, 768, 1024, 1536, 3072];

    private const DIM_MAX = 3072;

    private bool $isSqlite;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
        $this->isSqlite = $db->getDriverName() === 'sqlite';
    }

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
                'd.id as document_id',
                'd.title as document_title',
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

        $tsQuery = $this->db->table('document_chunks as dc')
            ->select(
                'dc.id',
                'dc.id as chunk_id',
                'dc.content',
                'd.id as document_id',
                'd.title as document_title',
                'dc.chunk_index',
                'dc.page_number',
                'd.created_at as document_created_at',
            )
            ->selectRaw('0.0 as similarity_score')
            ->selectRaw('ts_rank(dc.tsv_content, plainto_tsquery(\'simple\', ?)) as fts_score', [$queryText])
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at')
            ->whereRaw('dc.tsv_content @@ plainto_tsquery(\'simple\', ?)', [$queryText])
            ->orderByRaw('ts_rank(dc.tsv_content, plainto_tsquery(\'simple\', ?)) desc', [$queryText])
            ->limit($topK * 3);

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
                'd.id as document_id',
                'd.title as document_title',
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

    private function searchSqlite(array $queryVector, int $topK, array $filters, ?string $queryText = null): array
    {
        $threshold = (float) ($filters['similarity_threshold'] ?? 0.65);

        $query = $this->db->table('vector_embeddings as ve')
            ->select(
                've.id',
                've.chunk_id',
                'dc.content',
                'd.id as document_id',
                'd.title as document_title',
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

    public function delete(array $ids): void
    {
        if (! $this->isSqlite) {
            foreach (self::DIM_TABLES as $dim) {
                $this->db->table("ve_{$dim}")->whereIn('id', $ids)->delete();
            }
        }
        VectorEmbedding::whereIn('id', $ids)->delete();
    }

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
                $this->db->table("ve_{$dim}")
                    ->whereIn('chunk_id', $chunkIds)
                    ->delete();
            }
        }

        $this->db->table('vector_embeddings')
            ->whereIn('chunk_id', $chunkIds)
            ->delete();
    }

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
        if (isset($filters['model_name'])) {
            $query->where("{$alias}.model_name", $filters['model_name']);
        }

        return $query;
    }

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

        return $query;
    }

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

        $results = [];
        foreach (array_slice($scores, 0, $topK) as $item) {
            $row = $item['row'];
            $row->similarity_score = $item['score'];
            $results[] = $row;
        }

        return $results;
    }

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
