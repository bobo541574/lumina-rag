<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;
use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use Modules\DocumentModule\Jobs\ProcessDocumentJob;
use Modules\DocumentModule\Models\Document;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

class DocumentService
{
    private TextExtractionServiceInterface $extractor;

    private TextChunkingServiceInterface $chunker;

    private EmbeddingServiceInterface $embedder;

    private VectorStoreInterface $vectorStore;

    private Dispatcher $events;

    private array $allowedMimeTypes;

    private int $maxFileSize;

    private int $chunkSize;

    private int $overlap;

    private int $batchSize;

    public function __construct(
        TextExtractionServiceInterface $extractor,
        TextChunkingServiceInterface $chunker,
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
        Dispatcher $events,
        int $chunkSize = 1000,
        int $overlap = 200,
        int $batchSize = 100,
    ) {
        $this->extractor = $extractor;
        $this->chunker = $chunker;
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->events = $events;
        $this->allowedMimeTypes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'text/csv',
            'text/markdown',
        ];
        $this->maxFileSize = 50 * 1024 * 1024;
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
        $this->batchSize = $batchSize;
    }

    public function upload(UploadedFile $file, ?string $title = null, ?string $userId = null, ?string $embeddingModel = null): Document
    {
        $this->validateFile($file);
        $hash = hash_file('sha256', $file->getPathname());
        $existing = Document::where('file_hash', $hash)->first();

        if ($existing !== null) {
            throw new \RuntimeException('A document with this content already exists.');
        }

        $path = $file->store('documents');
        if ($path === false) {
            throw new \RuntimeException('Failed to store uploaded file');
        }

        $document = Document::create([
            'title' => $title ?? $file->getClientOriginalName(),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'file_hash' => $hash,
            'status' => 'pending',
            'user_id' => $userId,
            'embedding_model' => $embeddingModel ?? config('rag.embedding.model', 'text-embedding-3-small'),
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return $document->fresh();
    }

    public function retryDocument(string $id, ?string $userId = null): Document
    {
        $query = Document::where('id', $id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $document = $query->firstOrFail();

        if ($document->status !== 'failed') {
            throw new \RuntimeException('Only failed documents can be retried.');
        }

        $document->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return $document->fresh();
    }

    public function deleteDocument(string $id, ?string $userId = null): void
    {
        $query = Document::where('id', $id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $document = $query->firstOrFail();
        $this->vectorStore->deleteByDocumentId($document->id);
        $document->chunks()->delete();
        Storage::delete($document->file_path);
        $document->delete();
    }

    public function listDocuments(array $filters = [], ?string $userId = null): array
    {
        $query = Document::query();
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $perPage = (int) ($filters['per_page'] ?? 20);

        return $query->orderByDesc('created_at')
            ->paginate($perPage)
            ->toArray();
    }

    private function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload failed or file is corrupted');
        }

        if ($file->getSize() === 0) {
            throw new \InvalidArgumentException('Uploaded file is empty');
        }

        if ($file->getSize() > $this->maxFileSize) {
            $maxMb = $this->maxFileSize / 1024 / 1024;
            throw new \InvalidArgumentException("File size exceeds the {$maxMb}MB limit");
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, $this->allowedMimeTypes, true)) {
            throw new \InvalidArgumentException(
                "File type {$mime} is not supported. Allowed: PDF, DOCX, TXT, CSV, Markdown"
            );
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $expectedMime = $this->extensionToMime($extension);
        if ($expectedMime !== null && $expectedMime !== $mime) {
            throw new \InvalidArgumentException(
                "File extension .{$extension} does not match its MIME type ({$mime})"
            );
        }

        if (in_array($extension, ['pdf', 'docx'], true)) {
            $this->validateMagicBytes($file, $extension);
        }

        $this->validateFilename($file);
    }

    private function extensionToMime(string $extension): ?string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'md' => 'text/markdown',
            default => null,
        };
    }

    private function validateMagicBytes(UploadedFile $file, string $extension): void
    {
        $handle = fopen($file->getPathname(), 'rb');
        if ($handle === false) {
            return;
        }
        $bytes = fread($handle, 8);
        fclose($handle);

        $expected = match ($extension) {
            'pdf' => str_starts_with((string) $bytes, '%PDF-'),
            'docx' => str_starts_with((string) $bytes, "PK\x03\x04"),
            default => true,
        };

        if (! $expected) {
            throw new \InvalidArgumentException(
                "File content does not match its .{$extension} extension"
            );
        }
    }

    private function validateFilename(UploadedFile $file): void
    {
        $name = $file->getClientOriginalName();
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException('Filename contains invalid characters');
        }
    }
}
