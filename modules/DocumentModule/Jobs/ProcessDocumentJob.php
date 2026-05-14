<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;
use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public string $documentId;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [30, 300, 1800];

    public function __construct(string $documentId)
    {
        $this->documentId = $documentId;
    }

    public function handle(
        TextExtractionServiceInterface $extractor,
        TextChunkingServiceInterface $chunker,
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
    ): void {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => 'processing']);

        try {
            $this->cleanupPreviousAttempt($document, $vectorStore);

            $text = $this->extractText($document, $extractor);
            $chunks = $this->saveChunks($document, $text, $chunker);
            $this->generateEmbeddings($document, $chunks, $embedder, $vectorStore);

            $document->update([
                'status' => 'completed',
                'chunks_count' => count($chunks),
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);

        if ($document === null) {
            return;
        }

        $document->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }

    private function cleanupPreviousAttempt(Document $document, VectorStoreInterface $vectorStore): void
    {
        $existingCount = $document->chunks()->count();

        if ($existingCount === 0) {
            return;
        }

        $vectorStore->deleteByDocumentId($document->id);
        $document->chunks()->delete();
    }

    private function extractText(Document $document, TextExtractionServiceInterface $extractor): string
    {
        $fullPath = Storage::path($document->file_path);

        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$document->file_path}");
        }

        $text = $extractor->extract($fullPath, $document->mime_type);

        if (trim($text) === '') {
            throw new \RuntimeException('No extractable text found in document');
        }

        return $text;
    }

    private function saveChunks(
        Document $document,
        string $text,
        TextChunkingServiceInterface $chunker,
    ): array {
        $chunkSize = (int) config('rag.chunking.chunk_size', 1000);
        $overlap = (int) config('rag.chunking.overlap', 200);
        $rawChunks = $chunker->chunk($text, $chunkSize, $overlap);

        if ($rawChunks === []) {
            throw new \RuntimeException('Text chunking produced no chunks');
        }

        $now = now();
        $records = [];
        $ids = [];

        foreach ($rawChunks as $i => $chunkData) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            $records[] = [
                'id' => $id,
                'document_id' => $document->id,
                'content' => $chunkData['content'],
                'chunk_index' => $i,
                'char_start' => $chunkData['char_start'],
                'char_end' => $chunkData['char_end'],
                'token_count' => $this->estimateTokenCount($chunkData['content']),
                'page_number' => $chunkData['page_number'] ?? null,
                'created_at' => $now,
            ];
        }

        DB::transaction(function () use ($records): void {
            foreach (array_chunk($records, 500) as $batch) {
                DocumentChunk::insert($batch);
            }
        });

        return DocumentChunk::whereIn('id', $ids)
            ->orderBy('chunk_index')
            ->get()
            ->all();
    }

    private function generateEmbeddings(
        Document $document,
        array $chunks,
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
    ): void {
        $batchSize = (int) config('rag.embedding.batch_size', 100);
        $modelName = (string) config('rag.embedding.model', 'text-embedding-ada-002');

        $texts = array_map(fn (DocumentChunk $c): string => $c->content, $chunks);
        $textBatches = array_chunk($texts, $batchSize);

        foreach ($textBatches as $batchIndex => $batch) {
            $vectors = $embedder->embedBatch($batch);
            $offset = $batchIndex * $batchSize;

            $allVectors = [];
            $allChunkIds = [];
            $allMetadata = [];

            foreach ($vectors as $j => $vector) {
                $chunk = $chunks[$offset + $j];
                $allVectors[] = $vector;
                $allChunkIds[] = $chunk->id;
                $allMetadata[] = [
                    'model_name' => $modelName,
                    'content_hash' => md5($chunk->content),
                ];
            }

            $vectorStore->upsert(
                vectors: $allVectors,
                metadata: $allMetadata,
                chunkId: $allChunkIds,
                namespace: "document_{$document->id}",
            );
        }
    }

    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    public function tags(): array
    {
        return [
            'ProcessDocumentJob',
            "document:{$this->documentId}",
        ];
    }
}
