<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Services\EmbeddingService;
use Modules\VectorStoreModule\Services\VectorStoreService;

class ReEmbedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        EmbeddingService $embedder,
        VectorStoreService $vectorStore,
    ): void {
        $document = Document::findOrFail($this->documentId);

        $vectorStore->deleteByDocumentId($this->documentId);

        $chunks = DocumentChunk::where('document_id', $this->documentId)
            ->orderBy('chunk_index')
            ->get();

        if ($chunks->isEmpty()) {
            return;
        }

        $texts = $chunks->pluck('content')->toArray();
        $batchSize = (int) config('rag.embedding.batch_size', 100);
        $batches = array_chunk($texts, $batchSize);

        $modelName = config('rag.embedding.model', 'text-embedding-ada-002');

        foreach ($batches as $batchIndex => $batch) {
            $vectors = $embedder->embedBatch($batch);
            $offset = $batchIndex * $batchSize;

            foreach ($vectors as $j => $vector) {
                $chunk = $chunks[$offset + $j];
                $vectorStore->upsert(
                    vectors: [$vector],
                    metadata: [
                        'model_name' => $modelName,
                        'content_hash' => md5($chunk->content),
                    ],
                    chunkId: $chunk->id,
                    namespace: "document_{$document->id}",
                );
            }
        }
    }
}
