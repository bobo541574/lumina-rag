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

/**
 * Process Document Job
 *
 * Handles the async processing pipeline for a single uploaded document. Steps:
 * 1. Clean up any previous attempt (vectors + chunks).
 * 2. Extract text from the file based on its MIME type.
 * 3. Split text into chunks using recursive character splitting.
 * 4. Generate vector embeddings for each chunk (batched).
 * 5. Upsert vectors into the vector store.
 * 6. Mark the document as "completed" with chunk count.
 * On failure at any step, the document is marked "failed" with an error message.
 * Runs on the document-processing queue with 3 retries and backoff.
 *
 * @param  string  $documentId  The ULID of the document to process. Example: "01J..."
 *
 * @throws \RuntimeException If text extraction produces empty content or chunking yields no chunks
 */
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

    /**
     * Execute the job
     *
     * Loads the document, cleans up prior attempts, extracts text, chunks it,
     * generates embeddings via the resolved embedder, and upserts vectors.
     * On success, marks the document "completed". On any Throwable, marks it
     * "failed" and re-throws so the queue worker can manage retries.
     *
     * @param  TextExtractionServiceInterface  $extractor  Service to extract text from files. Example: app(TextExtractionServiceInterface::class)
     * @param  TextChunkingServiceInterface  $chunker  Service to split text into chunks. Example: app(TextChunkingServiceInterface::class)
     * @param  EmbeddingServiceInterface  $embedder  Default embedding service. Example: app(EmbeddingServiceInterface::class)
     * @param  VectorStoreInterface  $vectorStore  Service to persist vectors. Example: app(VectorStoreInterface::class)
     * @param  ProviderFactory  $providerFactory  Factory to create per-model providers. Example: app(ProviderFactory::class)
     * @param  CacheRepository  $cache  Cache repository for embedding caching. Example: app(CacheRepository::class)
     *
     * @throws \RuntimeException If text extraction produces empty content or chunking yields no chunks
     *                           Example: $job->handle(...) → RuntimeException("No extractable text found in document")
     * @throws \Throwable Any exception during processing is caught, logged to document, and re-thrown
     */
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

    /**
     * Handle job failure after all retries exhausted
     *
     * Marks the document as "failed" with the final error message and deletes
     * any chunks that may have been created during partial processing.
     *
     * @param  \Throwable  $e  The exception that caused the failure. Example: new RuntimeException("API timeout")
     */
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

    /**
     * Clean up vectors and chunks from a previous processing attempt
     *
     * If the document already has chunks (e.g., from a retry), deletes all
     * associated vectors from the vector store and all chunk records from the
     * database to ensure a clean slate for the new attempt.
     *
     * @param  Document  $document  The document being processed. Example: Document::findOrFail($id)
     * @param  VectorStoreInterface  $vectorStore  The vector store service. Example: app(VectorStoreInterface::class)
     */
    private function cleanupPreviousAttempt(Document $document, VectorStoreInterface $vectorStore): void
    {
        $existingCount = $document->chunks()->count();

        if ($existingCount === 0) {
            return;
        }

        $vectorStore->deleteByDocumentId($document->id);
        $document->chunks()->delete();
    }

    /**
     * Extract text from the document's file
     *
     * Resolves the full filesystem path, delegates to TextExtractionServiceInterface,
     * and validates that the extracted text is non-empty.
     *
     * @param  Document  $document  The document with file_path to extract from. Example: Document::findOrFail($id)
     * @param  TextExtractionServiceInterface  $extractor  The text extraction service. Example: app(TextExtractionServiceInterface::class)
     * @return string The extracted text content.
     *                Example: "Report by: John\nProject: Orion\nDate: 2026-05-01\n\nExecutive summary..."
     *
     * @throws \RuntimeException If the file does not exist on disk or extracted text is empty
     *                           Example: $this->extractText($doc, $extractor) → RuntimeException("File not found: documents/...")
     */
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

    /**
     * Split text into chunks and persist them to the database
     *
     * Uses the TextChunkingService to split the extracted text. Prepends a metadata
     * header (user name, project, date) to each chunk. Bulk-inserts chunks in
     * batches of 500 using raw SQL for performance. On PostgreSQL, also populates
     * the tsv_content full-text search vector column. Returns the inserted
     * DocumentChunk models ordered by chunk_index.
     *
     * @param  Document  $document  The document these chunks belong to. Example: Document::findOrFail($id)
     * @param  string  $text  The extracted text content to chunk. Example: "Executive summary..."
     * @param  TextChunkingServiceInterface  $chunker  The chunking service. Example: app(TextChunkingServiceInterface::class)
     * @return array List of DocumentChunk models ordered by chunk_index.
     *               Example: [DocumentChunk { id: "01J...", chunk_index: 0, content: "..." }, ...]
     *
     * @throws \RuntimeException If chunking produces no chunks
     *                           Example: $this->saveChunks($doc, "", $chunker) → RuntimeException("Text chunking produced no chunks")
     */
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

        // Build document-level metadata for all chunks
        $docMeta = [
            'user_id' => $document->user_id,
            'user_name' => $userName,
            'project' => $project,
            'report_date' => $reportDate,
            'document_title' => $document->title,
        ];

        $now = now();
        $records = [];
        $ids = [];

        foreach ($rawChunks as $i => $chunkData) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            $prefixed = $metaHeader.$chunkData['content'];

            // Merge chunk-level metadata with document-level metadata
            $chunkMeta = array_merge($docMeta, [
                'chunk_index' => $i,
                'section' => $chunkData['section'] ?? null,
                'page_number' => $chunkData['page_number'] ?? null,
            ]);
            $chunkMeta = array_filter($chunkMeta, fn ($v): bool => $v !== null);

            $records[] = [
                'id' => $id,
                'document_id' => $document->id,
                'content' => $prefixed,
                'chunk_index' => $i,
                'char_start' => $chunkData['char_start'],
                'char_end' => $chunkData['char_end'],
                'token_count' => $this->estimateTokenCount($prefixed),
                'page_number' => $chunkData['page_number'] ?? null,
                'metadata' => json_encode($chunkMeta),
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

    /**
     * Generate and store vector embeddings for all chunks
     *
     * Processes chunks in configurable batches (default 100). For each batch,
     * calls the embedder to generate vectors, then upserts them into the vector
     * store with metadata (model_name, content_hash) under the document namespace.
     *
     * @param  Document  $document  The document being processed (for namespace). Example: Document::findOrFail($id)
     * @param  array  $chunks  Array of DocumentChunk models to embed. Example: [DocumentChunk { id: "01J...", content: "..." }]
     * @param  EmbeddingServiceInterface  $embedder  The (possibly resolved) embedding service. Example: app(EmbeddingServiceInterface::class)
     * @param  VectorStoreInterface  $vectorStore  The vector store service. Example: app(VectorStoreInterface::class)
     */
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

    /**
     * Estimate the token count for a text string
     *
     * Uses a simple heuristic: divide character length by 4 and round up.
     * This is a rough approximation (OpenAI uses ~4 chars per token for English).
     *
     * @param  string  $text  The text to estimate. Example: "Hello world"
     * @return int Estimated token count. Example: 3 for "Hello world" (11 chars / 4 = 2.75 → 3)
     */
    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Get Horizon tags for this job
     *
     * Used by Laravel Horizon for monitoring and filtering.
     *
     * @return array Array of tag strings.
     *               Example: ["ProcessDocumentJob", "document:01J..."]
     */
    public function tags(): array
    {
        return [
            'ProcessDocumentJob',
            "document:{$this->documentId}",
        ];
    }
}
