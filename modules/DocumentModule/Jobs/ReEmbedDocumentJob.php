<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Re-Embed Document Job
 *
 * Regenerates vector embeddings for an already-processed document using its
 * configured embedding model. Deletes existing vectors before regenerating,
 * so this is safe to run multiple times. Used by the rag:reembed artisan command.
 * Runs on the document-processing queue with 3 retries and backoff.
 *
 * @param  string  $documentId  The ULID of the document to re-embed. Example: "01J..."
 */
class ReEmbedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesEmbedder, SerializesModels;

    public string $documentId;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [30, 300, 1800];

    public function __construct(string $documentId)
    {
        $this->documentId = $documentId;
        $this->onQueue('document-processing');
    }

    /**
     * Execute the job
     *
     * Loads the document, deletes all existing vectors for it, loads all chunks,
     * resolves the embedder (respecting per-document AiModel override), generates
     * embeddings in batches, and upserts them into the vector store. If the
     * document has no chunks, exits silently.
     *
     * @param  EmbeddingServiceInterface  $defaultEmbedder  Default embedding service. Example: app(EmbeddingServiceInterface::class)
     * @param  VectorStoreInterface  $vectorStore  The vector store service. Example: app(VectorStoreInterface::class)
     * @param  ProviderFactory  $providerFactory  Factory to create per-model providers. Example: app(ProviderFactory::class)
     * @param  CacheRepository  $cache  Cache repository for embedding caching. Example: app(CacheRepository::class)
     *
     * @throws ModelNotFoundException If the document does not exist
     *                                Example: ReEmbedDocumentJob::dispatch("nonexistent") → ModelNotFoundException
     */
    public function handle(
        EmbeddingServiceInterface $defaultEmbedder,
        VectorStoreInterface $vectorStore,
        ProviderFactory $providerFactory,
        CacheRepository $cache,
    ): void {
        $document = Document::findOrFail($this->documentId);

        $vectorStore->deleteByDocumentId($this->documentId);

        $chunks = DocumentChunk::where('document_id', $this->documentId)
            ->orderBy('chunk_index')
            ->get();

        if ($chunks->isEmpty()) {
            return;
        }

        $embedder = $this->resolveEmbedder($document, $defaultEmbedder, $providerFactory, $cache);
        $modelName = $this->resolveModelName($document);

        $texts = $chunks->pluck('content')->toArray();
        $batchSize = (int) config('rag.embedding.batch_size', 100);
        $batches = array_chunk($texts, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $vectors = $embedder->embedBatch($batch, $modelName);
            $offset = $batchIndex * $batchSize;

            $batchVectors = [];
            $batchChunkIds = [];
            $batchMetadata = [];

            foreach ($vectors as $j => $vector) {
                $chunk = $chunks[$offset + $j];
                $batchVectors[] = $vector;
                $batchChunkIds[] = $chunk->id;
                $batchMetadata[] = [
                    'model_name' => $modelName,
                    'content_hash' => md5($chunk->content),
                ];
            }

            $vectorStore->upsert(
                vectors: $batchVectors,
                metadata: $batchMetadata,
                chunkId: $batchChunkIds,
                namespace: "document_{$document->id}",
            );
        }
    }

    /**
     * Resolve the embedding model name for a document
     *
     * Returns the document's per-document embedding_model if set, otherwise
     * falls back to the global default from config.
     *
     * @param  Document  $document  The document to resolve the model for. Example: Document::findOrFail($id)
     * @return string The resolved model name.
     *                Example: "text-embedding-3-small"
     */
    private function resolveModelName(Document $document): string
    {
        return $document->embedding_model
            ?? (string) config('rag.embedding.model', 'text-embedding-3-small');
    }
}
