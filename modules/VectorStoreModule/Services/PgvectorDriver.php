<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Services;

use Illuminate\Database\DatabaseManager;
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

    public function upsert(array $vectors, array $metadata, string $chunkId, string $namespace): array
    {
        $ids = [];

        foreach ($vectors as $i => $vector) {
            $model = VectorEmbedding::create([
                'chunk_id' => $chunkId,
                'embedding' => $vector,
                'model_name' => $metadata['model_name'] ?? 'text-embedding-ada-002',
                'content_hash' => $metadata['content_hash'] ?? md5((string) $i),
            ]);

            $ids[] = $model->id;
        }

        return $ids;
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
                'dc.page_number'
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
