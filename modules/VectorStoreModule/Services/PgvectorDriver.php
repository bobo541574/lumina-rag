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

    private string $table;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
        $this->table = 'vector_embeddings';
    }

    public function upsert(array $vectors, array $metadata, string|array $chunkId, string $namespace): array
    {
        $chunkIds = is_array($chunkId) ? $chunkId : array_fill(0, count($vectors), $chunkId);
        $now = now()->toDateTimeString();
        $ids = [];

        $values = [];
        $bindings = [];

        foreach ($vectors as $i => $vector) {
            $meta = isset($metadata[$i]) ? $metadata[$i] : $metadata;
            $id = (string) Str::ulid();
            $ids[] = $id;

            $values[] = '(?, ?, ?::vector, ?, ?, ?::timestamptz)';
            $bindings[] = $id;
            $bindings[] = $chunkIds[$i] ?? $chunkIds[0];
            $bindings[] = '['.implode(',', $vector).']';
            $bindings[] = $meta['model_name'] ?? 'text-embedding-ada-002';
            $bindings[] = $meta['content_hash'] ?? md5((string) $i);
            $bindings[] = $now;
        }

        $sql = "INSERT INTO {$this->table} (id, chunk_id, embedding, model_name, content_hash, created_at) VALUES ".implode(', ', $values);
        $this->db->statement($sql, $bindings);

        return $ids;
    }

    public function searchHybrid(string $queryText, array $queryVector, int $topK = 5, array $filters = []): array
    {
        $vectorLiteral = '['.implode(',', $queryVector).']';

        $vectorQuery = $this->db->table($this->table, 've')
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

        $tsQuery = $this->db->table($this->table, 've')
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
            ->selectRaw('0.0 as similarity_score')
            ->selectRaw('ts_rank(dc.tsv_content, plainto_tsquery(\'simple\', ?)) as fts_score', [$queryText])
            ->join('document_chunks as dc', 'dc.id', '=', 've.chunk_id')
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->whereNull('d.deleted_at')
            ->whereRaw('dc.tsv_content @@ plainto_tsquery(\'simple\', ?)', [$queryText])
            ->orderByRaw('ts_rank(dc.tsv_content, plainto_tsquery(\'simple\', ?)) desc', [$queryText])
            ->limit($topK * 3);

        if (isset($filters['document_ids'])) {
            $vectorQuery->whereIn('d.id', (array) $filters['document_ids']);
            $tsQuery->whereIn('d.id', (array) $filters['document_ids']);
        }
        if (isset($filters['date_from'])) {
            $vectorQuery->where('d.created_at', '>=', $filters['date_from']);
            $tsQuery->where('d.created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $vectorQuery->where('d.created_at', '<=', $filters['date_to']);
            $tsQuery->where('d.created_at', '<=', $filters['date_to']);
        }
        if (isset($filters['model_name'])) {
            $vectorQuery->where('ve.model_name', $filters['model_name']);
            $tsQuery->where('ve.model_name', $filters['model_name']);
        }

        $threshold = (float) ($filters['similarity_threshold'] ?? 0.65);

        $vectorResults = $vectorQuery->get()->toArray();
        $ftsResults = $tsQuery->get()->toArray();

        $vectorResults = array_values(
            array_filter($vectorResults, fn (object $row): bool => $row->similarity_score >= $threshold)
        );

        $fused = $this->fuseResults($vectorResults, $ftsResults, $topK);

        return $fused;
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

    public function search(array $queryVector, int $topK = 5, array $filters = []): array
    {
        $vectorLiteral = '['.implode(',', $queryVector).']';

        $query = $this->db->table($this->table, 've')
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
            $query->where('ve.model_name', $filters['model_name']);
        }

        $threshold = (float) ($filters['similarity_threshold'] ?? 0.65);

        $results = $query->get()->toArray();

        return array_values(
            array_filter($results, fn (object $row): bool => $row->similarity_score >= $threshold)
        );
    }

    public function delete(array $ids): void
    {
        VectorEmbedding::whereIn('id', $ids)->delete();
    }

    public function deleteByDocumentId(string $documentId): void
    {
        $this->db->table($this->table, 've')
            ->join('document_chunks as dc', 'dc.id', '=', 've.chunk_id')
            ->where('dc.document_id', $documentId)
            ->delete();
    }

    public function getStats(): array
    {
        $count = VectorEmbedding::count();
        $modelCounts = VectorEmbedding::selectRaw('model_name, count(*) as count')
            ->groupBy('model_name')
            ->pluck('count', 'model_name')
            ->toArray();

        return [
            'total_vectors' => $count,
            'by_model' => $modelCounts,
        ];
    }
}
