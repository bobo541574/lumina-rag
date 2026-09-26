<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;
use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Process Document Service
 *
 * Handles the document processing pipeline for a single uploaded document. Steps:
 * 1. Clean up any previous attempt (vectors + chunks).
 * 2. Extract text from the file based on its MIME type.
 * 3. Split text into chunks using recursive character splitting.
 * 4. Generate vector embeddings for each chunk (batched).
 * 5. Upsert vectors into the vector store.
 * 6. Mark the document as "completed" with chunk count.
 * On failure at any step, the document is marked "failed" with an error message.
 *
 * Holds the business logic; the queue job (ProcessDocumentJob) is a thin
 * transport wrapper so the same pipeline can be invoked synchronously by
 * console commands, tests, or other services without dispatching a job.
 *
 * @param  TextExtractionServiceInterface  $extractor  Extracts raw text from stored files. Example: app(TextExtractionServiceInterface::class)
 * @param  TextChunkingServiceInterface  $chunker  Splits extracted text into manageable chunks. Example: app(TextChunkingServiceInterface::class)
 * @param  EmbeddingServiceInterface  $embedder  Default embedding service (overridable per document). Example: app(EmbeddingServiceInterface::class)
 * @param  VectorStoreInterface  $vectorStore  Persists/removes vector embeddings. Example: app(VectorStoreInterface::class)
 * @param  ProviderFactory  $providerFactory  Factory to create per-model providers. Example: app(ProviderFactory::class)
 * @param  ChunkWriter  $chunkWriter  Builds and inserts chunk rows. Example: app(ChunkWriter::class)
 * @param  ChunkEmbedder  $chunkEmbedder  Embeds chunks and stores their vectors. Example: app(ChunkEmbedder::class)
 */
class ProcessDocumentService
{
    use ResolvesEmbedder;

    private TextExtractionServiceInterface $extractor;

    private TextChunkingServiceInterface $chunker;

    private EmbeddingServiceInterface $embedder;

    private VectorStoreInterface $vectorStore;

    private ProviderFactory $providerFactory;

    private ChunkWriter $chunkWriter;

    private ChunkEmbedder $chunkEmbedder;

    public function __construct(
        TextExtractionServiceInterface $extractor,
        TextChunkingServiceInterface $chunker,
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
        ProviderFactory $providerFactory,
        ChunkWriter $chunkWriter,
        ChunkEmbedder $chunkEmbedder,
    ) {
        $this->extractor = $extractor;
        $this->chunker = $chunker;
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->providerFactory = $providerFactory;
        $this->chunkWriter = $chunkWriter;
        $this->chunkEmbedder = $chunkEmbedder;
    }

    /**
     * Execute the document processing pipeline
     *
     * Loads the document, cleans up prior attempts, extracts text, chunks it,
     * generates embeddings via the resolved embedder, and upserts vectors.
     * On success, marks the document "completed". On any Throwable it
     * re-throws so the caller can manage retries; the document is marked
     * "failed" only on the final attempt, otherwise it stays "processing" with
     * the error recorded.
     *
     * @param  string  $documentId  The ULID of the document to process. Example: "01J..."
     * @param  bool  $finalAttempt  Whether a failure is final (no queue retry left). Example: true
     *
     * A document that no longer exists is skipped (logged), not an error.
     *
     * @throws \RuntimeException If text extraction produces empty content or chunking yields no chunks
     *                           Example: $service->process($id) → RuntimeException("No extractable text found in document")
     * @throws \Throwable Any exception during processing is caught, logged to the document, and re-thrown
     */
    public function process(string $documentId, bool $finalAttempt = true): void
    {
        $document = Document::find($documentId);
        if ($document === null) {
            // Deleted (or the database was reset) after the job was queued.
            // Nothing to do, and a queue retry cannot bring it back.
            Log::channel(config('rag.logging.channel', 'rag'))->info('Processing skipped: document no longer exists', ['document_id' => $documentId]);

            return;
        }
        $document->markProcessing();

        try {
            $this->cleanupPreviousAttempt($document);

            $text = $this->extractText($document);
            $chunks = $this->saveChunks($document, $text);

            $embedder = $this->resolveEmbedder($document, $this->embedder, $this->providerFactory);
            $this->chunkEmbedder->store($document, $chunks, $this->chunkEmbedder->embed($document, $chunks, $embedder));
            $this->validateEmbedCount($document, $chunks);

            $document->markCompleted(count($chunks));

            // New knowledge is available — invalidate cached RAG answers.
            KnowledgeChanged::dispatch([$document->id]);
        } catch (\Throwable $e) {
            if ($finalAttempt) {
                $this->markFailed($documentId, $e->getMessage());
            } else {
                // The queue will retry: keep the document "processing" so the
                // UI does not offer a manual retry that would race this one.
                $document->update(['error_message' => $e->getMessage()]);
            }

            throw $e;
        }
    }

    /**
     * Mark a document as failed with the given error message
     *
     * @param  string  $documentId  The ULID of the document. Example: "01J..."
     * @param  string  $errorMessage  The failure reason to persist. Example: "API timeout"
     */
    public function markFailed(string $documentId, string $errorMessage): void
    {
        $document = Document::find($documentId);

        if ($document === null) {
            return;
        }

        $document->markFailed($errorMessage);
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
     */
    private function cleanupPreviousAttempt(Document $document): void
    {
        $existingCount = $document->chunks()->count();

        if ($existingCount === 0) {
            return;
        }

        $this->vectorStore->deleteByDocumentId($document->id);
        $document->chunks()->delete();
    }

    /**
     * Extract text from the document's file
     *
     * Resolves the full filesystem path, delegates to TextExtractionServiceInterface,
     * and validates that the extracted text is non-empty.
     *
     * @param  Document  $document  The document with file_path to extract from. Example: Document::findOrFail($id)
     * @return string The extracted text content.
     *                Example: "Report by: John\nProject: Orion\nDate: 2026-05-01\n\nExecutive summary..."
     *
     * @throws \RuntimeException If the file does not exist on disk or extracted text is empty
     *                           Example: $service->extractText($doc) → RuntimeException("File not found: documents/...")
     */
    private function extractText(Document $document): string
    {
        $fullPath = Storage::path($document->file_path);

        if (! file_exists($fullPath)) {
            throw new \RuntimeException("File not found: {$document->file_path}");
        }

        $text = $this->extractor->extract($fullPath, $document->mime_type);

        if (trim($text) === '') {
            throw new \RuntimeException('No extractable text found in document');
        }

        return $text;
    }

    /**
     * Split text into chunks and persist them to the database
     *
     * Uses the TextChunkingService to split the extracted text, then ChunkWriter
     * to build rows (metadata header + JSON metadata) and bulk-insert them with
     * their tsvector. Returns the inserted
     * DocumentChunk models ordered by chunk_index.
     *
     * @param  Document  $document  The document these chunks belong to. Example: Document::findOrFail($id)
     * @param  string  $text  The extracted text content to chunk. Example: "Executive summary..."
     * @return array List of DocumentChunk models ordered by chunk_index.
     *               Example: [DocumentChunk { id: "01J...", chunk_index: 0, content: "..." }, ...]
     *
     * @throws \RuntimeException If chunking produces no chunks
     *                           Example: $service->saveChunks($doc, "") → RuntimeException("Text chunking produced no chunks")
     */
    private function saveChunks(Document $document, string $text): array
    {
        $rawChunks = $this->chunker->chunk(
            $text,
            (int) config('rag.chunking.chunk_size', 1000),
            (int) config('rag.chunking.overlap', 200),
        );

        if ($rawChunks === []) {
            throw new \RuntimeException('Text chunking produced no chunks');
        }

        $records = $this->chunkWriter->buildRecords($document->id, $rawChunks, [
            'user_id' => $document->user_id,
            'user_name' => User::find($document->user_id)?->name ?? 'Unknown',
            'project' => $document->project ?? 'General',
            'report_date' => $document->report_date?->format('Y-m-d') ?? $document->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'document_title' => $document->title,
        ], now());

        DB::transaction(fn () => $this->chunkWriter->insert($records));

        return DocumentChunk::where('document_id', $document->id)
            ->orderBy('chunk_index')
            ->get()
            ->all();
    }

    /**
     * Validate that every chunk has a vector row after embedding
     *
     * Counts actual vector rows across all ve_* shard tables for the document's
     * chunks and logs a warning when the count does not match the expected total.
     * Does not throw — a mismatch is recoverable via `rag:reembed --missing`.
     *
     * @param  Document  $document  The processed document. Example: Document::findOrFail($id)
     * @param  array  $chunks  Array of saved DocumentChunk models. Example: [DocumentChunk { id: "01J..." }]
     */
    private function validateEmbedCount(Document $document, array $chunks): void
    {
        $expected = count($chunks);
        $actual = $this->chunkEmbedder->countStored($chunks);

        if ($actual < $expected) {
            Log::channel(config('rag.logging.channel', 'rag'))->warning(
                'ProcessDocumentService: embed count mismatch',
                [
                    'document_id' => $document->id,
                    'expected' => $expected,
                    'actual' => $actual,
                    'missing' => $expected - $actual,
                ]
            );
        }
    }
}
