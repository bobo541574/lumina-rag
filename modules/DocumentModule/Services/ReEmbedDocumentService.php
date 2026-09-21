<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Re-Embed Document Service
 *
 * Regenerates vector embeddings for an already-processed document using its
 * configured embedding model. Deletes existing vectors before regenerating,
 * so this is safe to run multiple times. Holds the business logic so the same
 * operation can be invoked synchronously (console, tests) or via the queue
 * wrapper ReEmbedDocumentJob.
 *
 * @param  EmbeddingServiceInterface  $defaultEmbedder  Default embedding service (overridable per document). Example: app(EmbeddingServiceInterface::class)
 * @param  VectorStoreInterface  $vectorStore  Persists/removes vector embeddings. Example: app(VectorStoreInterface::class)
 * @param  ProviderFactory  $providerFactory  Factory to create per-model providers. Example: app(ProviderFactory::class)
 * @param  CacheRepository  $cache  Cache repository for embedding caching. Example: app(CacheRepository::class)
 */
class ReEmbedDocumentService
{
    use ResolvesEmbedder;

    private EmbeddingServiceInterface $defaultEmbedder;

    private VectorStoreInterface $vectorStore;

    private ProviderFactory $providerFactory;

    private CacheRepository $cache;

    public function __construct(
        EmbeddingServiceInterface $defaultEmbedder,
        VectorStoreInterface $vectorStore,
        ProviderFactory $providerFactory,
        CacheRepository $cache,
    ) {
        $this->defaultEmbedder = $defaultEmbedder;
        $this->vectorStore = $vectorStore;
        $this->providerFactory = $providerFactory;
        $this->cache = $cache;
    }

    /**
     * Re-embed a document
     *
     * Loads the document, deletes all existing vectors for it, loads all chunks,
     * resolves the embedder (respecting per-document AiModel override), generates
     * embeddings in batches, and upserts them into the vector store. If the
     * document has no chunks, exits silently.
     *
     * @param  string  $documentId  The ULID of the document to re-embed. Example: "01J..."
     *
     * @throws ModelNotFoundException If the document does not exist
     *                                Example: $service->reEmbed("nonexistent") → ModelNotFoundException
     */
    public function reEmbed(string $documentId): void
    {
        $document = Document::findOrFail($documentId);

        $this->vectorStore->deleteByDocumentId($documentId);

        $chunks = DocumentChunk::where('document_id', $documentId)
            ->orderBy('chunk_index')
            ->get();

        if ($chunks->isEmpty()) {
            return;
        }

        $embedder = $this->resolveEmbedder($document, $this->defaultEmbedder, $this->providerFactory, $this->cache);
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

            $this->vectorStore->upsert(
                vectors: $batchVectors,
                metadata: $batchMetadata,
                chunkId: $batchChunkIds,
                namespace: "document_{$document->id}",
            );
        }

        // Vectors regenerated — cached answers may have changed.
        KnowledgeChanged::dispatch([$document->id]);
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
