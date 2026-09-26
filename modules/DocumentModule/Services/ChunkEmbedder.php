<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Embeds document chunks and writes their vectors to the vector store.
 *
 * Split into embed() and store() so callers decide when old vectors are
 * replaced: processing stores right away, re-embedding computes everything
 * first and swaps inside a transaction.
 */
class ChunkEmbedder
{
    /**
     * @param  VectorStoreInterface  $vectorStore  Destination for vectors. Example: app(VectorStoreInterface::class)
     */
    public function __construct(private VectorStoreInterface $vectorStore) {}

    /**
     * Compute one vector per chunk, in chunk order.
     *
     * @param  Document  $document  Owning document (selects the model name). Example: Document::find("01J...")
     * @param  array<DocumentChunk>  $chunks  Chunks ordered by chunk_index
     * @param  EmbeddingServiceInterface  $embedder  Embedder for the document's model
     * @return array<array<float>> Vectors aligned with $chunks
     *
     * @throws \RuntimeException When the embedding provider fails
     */
    public function embed(Document $document, array $chunks, EmbeddingServiceInterface $embedder): array
    {
        $modelName = $document->embeddingModelName();
        $texts = array_map(fn (DocumentChunk $chunk): string => $chunk->content, $chunks);
        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize()) as $batch) {
            array_push($vectors, ...$embedder->embedBatch($batch, $modelName));
        }

        if (count($vectors) !== count($chunks)) {
            throw new \RuntimeException('Embedding provider returned '.count($vectors).' vectors for '.count($chunks).' chunks');
        }

        return $vectors;
    }

    /**
     * Upsert pre-computed vectors for the given chunks.
     *
     * @param  Document  $document  Owning document. Example: Document::find("01J...")
     * @param  array<DocumentChunk>  $chunks  Chunks aligned with $vectors
     * @param  array<array<float>>  $vectors  Output of embed()
     */
    public function store(Document $document, array $chunks, array $vectors): void
    {
        $modelName = $document->embeddingModelName();

        foreach (array_chunk(array_keys($chunks), $this->batchSize()) as $indexes) {
            $this->vectorStore->upsert(
                vectors: array_map(fn (int $i): array => $vectors[$i], $indexes),
                metadata: array_map(fn (int $i): array => [
                    'model_name' => $modelName,
                    'content_hash' => md5($chunks[$i]->content),
                ], $indexes),
                chunkId: array_map(fn (int $i): string => $chunks[$i]->id, $indexes),
                namespace: "document_{$document->id}",
            );
        }
    }

    /**
     * Number of vectors currently stored for the given chunks.
     *
     * @param  array<DocumentChunk>  $chunks  Chunks to check
     * @return int Stored vector count. Example: 42
     */
    public function countStored(array $chunks): int
    {
        return $this->vectorStore->countByChunkIds(array_map(fn (DocumentChunk $chunk): string => $chunk->id, $chunks));
    }

    private function batchSize(): int
    {
        return max(1, (int) config('rag.embedding.batch_size', 100));
    }
}
