<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\DocumentModule\Services\TextChunkingService;
use Modules\DocumentModule\Services\TextExtractionService;
use Modules\EmbeddingModule\Services\EmbeddingService;
use Modules\VectorStoreModule\Services\VectorStoreService;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $documentId;

    public Document $document;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [30, 300, 1800];

    public function __construct(string $documentId)
    {
        $this->documentId = $documentId;
        $this->onQueue('document-processing');
    }

    public function handle(
        TextExtractionService $extractor,
        TextChunkingService $chunker,
        EmbeddingService $embedder,
        VectorStoreService $vectorStore,
    ): void {
        $this->document = Document::findOrFail($this->documentId);
        $this->document->update(['status' => 'processing']);

        try {
            $fullPath = Storage::path($this->document->file_path);
            $text = $extractor->extract($fullPath, $this->document->mime_type);

            if (trim($text) === '') {
                throw new \RuntimeException('No extractable text found in document');
            }

            $chunkSize = (int) config('rag.chunking.chunk_size', 1000);
            $overlap = (int) config('rag.chunking.overlap', 200);
            $rawChunks = $chunker->chunk($text, $chunkSize, $overlap);

            $chunkRecords = [];
            foreach ($rawChunks as $i => $chunkData) {
                $chunkRecords[] = DocumentChunk::create([
                    'document_id' => $this->document->id,
                    'content' => $chunkData['content'],
                    'chunk_index' => $i,
                    'char_start' => $chunkData['char_start'],
                    'char_end' => $chunkData['char_end'],
                ]);
            }

            $batchSize = (int) config('rag.embedding.batch_size', 100);
            $texts = array_map(fn (DocumentChunk $c): string => $c->content, $chunkRecords);
            $batches = array_chunk($texts, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $vectors = $embedder->embedBatch($batch);
                $offset = $batchIndex * $batchSize;

                foreach ($vectors as $j => $vector) {
                    $chunk = $chunkRecords[$offset + $j];
                    $vectorStore->upsert(
                        vectors: [$vector],
                        metadata: [
                            'model_name' => config('rag.embedding.model', 'text-embedding-ada-002'),
                            'content_hash' => md5($chunk->content),
                        ],
                        chunkId: $chunk->id,
                        namespace: "document_{$this->document->id}",
                    );
                }
            }

            $this->document->update([
                'status' => 'completed',
                'chunks_count' => count($chunkRecords),
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function tags(): array
    {
        return [
            'ProcessDocumentJob', 
            "document:{$this->documentId}",
            "name:{$this->document->original_filename}",
        ];
    }
}
