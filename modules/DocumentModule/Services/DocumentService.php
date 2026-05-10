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

    public function upload(UploadedFile $file, ?string $title = null): Document
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
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return $document->fresh();
    }

    public function deleteDocument(string $id): void
    {
        $document = Document::findOrFail($id);
        $this->vectorStore->deleteByDocumentId($document->id);
        $document->chunks()->delete();
        Storage::delete($document->file_path);
        $document->delete();
    }

    public function listDocuments(array $filters = []): array
    {
        $query = Document::query();
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
        $mime = $file->getMimeType();
        if (! in_array($mime, $this->allowedMimeTypes, true)) {
            throw new \InvalidArgumentException(
                "File type {$mime} is not supported. Allowed: PDF, DOCX, TXT, CSV, Markdown"
            );
        }
        if ($file->getSize() > $this->maxFileSize) {
            throw new \InvalidArgumentException('File size exceeds the 50MB limit');
        }
        if (! $file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload failed or file is corrupted');
        }
    }
}
