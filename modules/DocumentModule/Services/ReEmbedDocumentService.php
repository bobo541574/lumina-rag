<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\DocumentModule\Enums\DocumentStatus;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Re-Embed Document Service
 *
 * Regenerates vector embeddings for an already-processed document using its
 * configured embedding model. New vectors are computed first and swapped in
 * atomically, so a provider failure leaves the old vectors in place and this
 * is safe to run multiple times. Holds the business logic so the same
 * operation can be invoked synchronously (console, tests) or via the queue
 * wrapper ReEmbedDocumentJob.
 *
 * @param  EmbeddingServiceInterface  $defaultEmbedder  Default embedding service (overridable per document). Example: app(EmbeddingServiceInterface::class)
 * @param  VectorStoreInterface  $vectorStore  Persists/removes vector embeddings. Example: app(VectorStoreInterface::class)
 * @param  ProviderFactory  $providerFactory  Factory to create per-model providers. Example: app(ProviderFactory::class)
 * @param  ChunkEmbedder  $chunkEmbedder  Embeds chunks and stores their vectors. Example: app(ChunkEmbedder::class)
 */
class ReEmbedDocumentService
{
    use ResolvesEmbedder;

    private EmbeddingServiceInterface $defaultEmbedder;

    private VectorStoreInterface $vectorStore;

    private ProviderFactory $providerFactory;

    private ChunkEmbedder $chunkEmbedder;

    public function __construct(
        EmbeddingServiceInterface $defaultEmbedder,
        VectorStoreInterface $vectorStore,
        ProviderFactory $providerFactory,
        ChunkEmbedder $chunkEmbedder,
    ) {
        $this->defaultEmbedder = $defaultEmbedder;
        $this->vectorStore = $vectorStore;
        $this->providerFactory = $providerFactory;
        $this->chunkEmbedder = $chunkEmbedder;
    }

    /**
     * Re-embed a document
     *
     * Loads all chunks, resolves the embedder (respecting per-document AiModel
     * override) and generates embeddings in batches. Only once every batch
     * succeeds are the old vectors replaced, inside one DB transaction. If the
     * document has no chunks, its vectors are removed and the call returns.
     *
     * @param  string  $documentId  The ULID of the document to re-embed. Example: "01J..."
     *
     * A document that no longer exists is skipped (logged), not an error.
     */
    public function reEmbed(string $documentId): void
    {
        $document = Document::find($documentId);
        if ($document === null) {
            // Deleted (or the database was reset) after the job was queued.
            // Nothing to do, and a queue retry cannot bring it back.
            Log::channel(config('rag.logging.channel', 'rag'))->info('Re-embed skipped: document no longer exists', ['document_id' => $documentId]);

            return;
        }

        $chunks = DocumentChunk::where('document_id', $documentId)
            ->orderBy('chunk_index')
            ->get()
            ->all();

        if ($chunks === []) {
            $this->vectorStore->deleteByDocumentId($documentId);

            return;
        }

        // Embed everything before touching the store: if the provider fails
        // part-way, the document keeps its old vectors instead of none.
        $embedder = $this->resolveEmbedder($document, $this->defaultEmbedder, $this->providerFactory);
        $vectors = $this->chunkEmbedder->embed($document, $chunks, $embedder);

        DB::transaction(function () use ($document, $chunks, $vectors): void {
            $this->vectorStore->deleteByDocumentId($document->id);
            $this->chunkEmbedder->store($document, $chunks, $vectors);
        });

        // Clear a stale error left by an earlier failed re-embed.
        if ($document->status === DocumentStatus::Completed && $document->error_message !== null) {
            $document->update(['error_message' => null]);
        }

        // Vectors regenerated — cached answers may have changed.
        KnowledgeChanged::dispatch([$document->id]);
    }
}
