<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

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

    private function resolveModelName(Document $document): string
    {
        return $document->embedding_model
            ?? (string) config('rag.embedding.model', 'text-embedding-3-small');
    }
}
