<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesEmbedder;

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
        ProviderFactory $providerFactory,
        CacheRepository $cache,
    ): void {
        $document = Document::findOrFail($this->documentId);
        $document->update(['status' => 'processing']);

        try {
            $this->cleanupPreviousAttempt($document, $vectorStore);

            $text = $this->extractText($document, $extractor);
            $chunks = $this->saveChunks($document, $text, $chunker);

            $embedderToUse = $this->resolveEmbedder($document, $embedder, $providerFactory, $cache);
            $this->generateEmbeddings($document, $chunks, $embedderToUse, $vectorStore);

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

        $document->chunks()->delete();
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

        // Build metadata header from document fields
        $userName = 'Unknown';
        try {
            $user = User::find($document->user_id);
            if ($user !== null) {
                $userName = $user->name;
            }
        } catch (\Throwable) {
            // ignore
        }
        $project = $document->project ?? 'General';
        $reportDate = $document->report_date ?? $document->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        $metaHeader = "Report by: {$userName}\nProject: {$project}\nDate: {$reportDate}\n\n";

        $now = now();
        $records = [];
        $ids = [];

        foreach ($rawChunks as $i => $chunkData) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            $prefixed = $metaHeader.$chunkData['content'];
            $records[] = [
                'id' => $id,
                'document_id' => $document->id,
                'content' => $prefixed,
                'chunk_index' => $i,
                'char_start' => $chunkData['char_start'],
                'char_end' => $chunkData['char_end'],
                'token_count' => $this->estimateTokenCount($prefixed),
                'page_number' => $chunkData['page_number'] ?? null,
                'created_at' => $now,
            ];
        }

        DB::transaction(function () use ($records): void {
            foreach (array_chunk($records, 500) as $batch) {
                $columns = implode(', ', array_keys($batch[0]));
                $values = [];
                $bindings = [];

                foreach ($batch as $row) {
                    $placeholders = array_fill(0, count($row), '?');
                    $bindings = array_merge($bindings, array_values($row));
                    $values[] = '('.implode(', ', $placeholders).')';
                }

                $sql = "INSERT INTO document_chunks ({$columns}) VALUES ".implode(', ', $values);
                DB::statement($sql, $bindings);
            }

            if (DB::getDriverName() === 'pgsql') {
                $ids = array_column($records, 'id');
                foreach (array_chunk($ids, 500) as $idBatch) {
                    $placeholders = implode(', ', array_fill(0, count($idBatch), '?'));
                    DB::statement(
                        "UPDATE document_chunks SET tsv_content = to_tsvector('english', content) WHERE id IN ({$placeholders}) AND tsv_content IS NULL",
                        $idBatch,
                    );
                }
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
        $modelName = $document->embedding_model ?? (string) config('rag.embedding.model', 'text-embedding-3-small');

        $texts = array_map(fn (DocumentChunk $c): string => $c->content, $chunks);
        $textBatches = array_chunk($texts, $batchSize);

        foreach ($textBatches as $batchIndex => $batch) {
            $vectors = $embedder->embedBatch($batch, $modelName);
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
