<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\DocumentModule\Contracts\DocumentServiceInterface;
use Modules\DocumentModule\Enums\DocumentStatus;
use Modules\DocumentModule\Exceptions\InvalidDocumentStateException;
use Modules\DocumentModule\Jobs\ProcessDocumentJob;
use Modules\DocumentModule\Models\Document;
use Modules\SettingsModule\Models\AiModel;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Document Service
 *
 * Orchestrates the full document lifecycle: upload validation, persistent storage,
 * deduplication via SHA-256, and dispatching async processing jobs. Also handles
 * CRUD operations (list, update, delete, retry) for document records.
 * Text extraction, chunking and embedding happen asynchronously in
 * ProcessDocumentJob / ProcessDocumentService.
 *
 * @param  VectorStoreInterface  $vectorStore  Removes a document's vectors on delete. Example: mock(VectorStoreInterface::class)
 */
class DocumentService implements DocumentServiceInterface
{
    /** @var array<string> MIME types accepted for upload */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/csv',
        'text/markdown',
    ];

    /** @var int Maximum upload size in bytes (50 MB) */
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    public function __construct(private VectorStoreInterface $vectorStore) {}

    /**
     * Upload a document file
     *
     * Validates the uploaded file (MIME type, size, extension/content match, filename
     * safety), computes a SHA-256 hash for deduplication, stores the file to the
     * configured disk, creates a Document record with status "pending", and dispatches
     * a ProcessDocumentJob for async text extraction/chunking/embedding.
     *
     * @param  UploadedFile  $file  The uploaded file instance. Example: request()->file('file')
     * @param  string|null  $title  Optional human-readable title; defaults to original filename. Example: "Q3 Report"
     * @param  string|null  $userId  Optional ULID of the owning user. Example: "01J..."
     * @param  string|null  $embeddingModel  Optional embedding model name override. Example: "text-embedding-3-small"
     * @param  string|null  $embeddingModelId  Optional ULID of an AiModel record for embedding. Example: "01J..."
     * @param  string|null  $reportDate  Optional report date string (Y-m-d). Example: "2026-05-01"
     * @param  string|null  $project  Optional project name for grouping documents. Example: "Orion"
     * @return Document The newly created document record (fresh from DB).
     *                  Example: Document { id: "01J...", title: "Q3 Report", status: "pending" }
     *
     * @throws \RuntimeException If a document with the same SHA-256 hash already exists (duplicate)
     *                           Example: $service->upload($duplicateFile) → RuntimeException("A document with this content already exists.")
     * @throws \RuntimeException If the file cannot be stored on disk
     *                           Example: $service->upload($corruptFile) → RuntimeException("Failed to store uploaded file")
     * @throws \InvalidArgumentException If the file fails validation (bad type, size, extension mismatch, etc.)
     *                                   Example: $service->upload($exeFile) → InvalidArgumentException("File type ... not supported")
     */
    public function upload(UploadedFile $file, ?string $title = null, ?string $userId = null, ?string $embeddingModel = null, ?string $embeddingModelId = null, ?string $reportDate = null, ?string $project = null): Document
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

        $modelName = $embeddingModel;
        $modelId = $embeddingModelId;

        if ($embeddingModelId !== null) {
            $aiModel = AiModel::find($embeddingModelId);
            if ($aiModel !== null && $aiModel->type === 'embedding') {
                $modelName = $aiModel->modelName();
            }
        }

        if ($modelName === null) {
            $modelName = config('rag.embedding.model', 'text-embedding-3-small');
        }

        $document = Document::create([
            'title' => $title ?? $file->getClientOriginalName(),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'file_hash' => $hash,
            'status' => DocumentStatus::Pending,
            'user_id' => $userId,
            'report_date' => $reportDate,
            'project' => $project,
            'embedding_model' => $modelName,
            'embedding_model_id' => $modelId,
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return $document->fresh();
    }

    /**
     * Retry processing a failed document
     *
     * Resets a failed document's status back to "pending", clears the error message,
     * and dispatches a new ProcessDocumentJob. Only documents with status "failed"
     * may be retried; other statuses are rejected.
     *
     * @param  string  $id  The ULID of the document to retry. Example: "01J..."
     * @param  string  $userId  Owner ULID; other users' documents are not found. Example: "01J..."
     * @return Document The updated document record (fresh from DB) with status "pending".
     *                  Example: Document { id: "01J...", status: "pending", error_message: null }
     *
     * @throws InvalidDocumentStateException If the document status is not "failed"
     *                                       Example: $service->retryDocument($completedId, $userId) → InvalidDocumentStateException("Only failed documents can be retried.")
     */
    public function retryDocument(string $id, string $userId): Document
    {
        $document = $this->findOwned($id, $userId);

        if (! $document->status->canRetry()) {
            throw new InvalidDocumentStateException('Only failed documents can be retried.');
        }

        $document->markPendingRetry();

        ProcessDocumentJob::dispatch($document->id);

        return $document->fresh();
    }

    /**
     * Delete a document and all its associated data
     *
     * Removes vector embeddings from the vector store, deletes all DocumentChunk
     * records, removes the physical file from storage, and soft-deletes the Document
     * record. This is irreversible.
     *
     * @param  string  $id  The ULID of the document to delete. Example: "01J..."
     * @param  string  $userId  Owner ULID; other users' documents are not found. Example: "01J..."
     *
     * @throws ModelNotFoundException If the document does not exist
     *                                Example: $service->deleteDocument("nonexistent") → ModelNotFoundException
     */
    public function deleteDocument(string $id, string $userId): void
    {
        $document = $this->findOwned($id, $userId);
        $this->vectorStore->deleteByDocumentId($document->id);
        $document->chunks()->delete();
        Storage::delete($document->file_path);
        $document->delete();

        // Notify the RAG pipeline to invalidate cached answers referencing this doc.
        KnowledgeChanged::dispatch([$document->id]);
    }

    /**
     * Update document metadata fields
     *
     * Updates only the allowed metadata fields (title, description, report_date, project).
     * All other keys in the $data array are silently ignored. Throws if the document
     * does not exist.
     *
     * @param  string  $id  The ULID of the document to update. Example: "01J..."
     * @param  array  $data  Associative array of fields to update. Example: ["title" => "Updated Title", "project" => "Orion"]
     * @param  string  $userId  Owner ULID; other users' documents are not found. Example: "01J..."
     * @return Document The updated document record (fresh from DB).
     *                  Example: Document { id: "01J...", title: "Updated Title", project: "Orion" }
     *
     * @throws ModelNotFoundException If the document does not exist
     *                                Example: $service->updateDocument("nonexistent", []) → ModelNotFoundException
     */
    public function updateDocument(string $id, array $data, string $userId): Document
    {
        $document = $this->findOwned($id, $userId);

        $allowed = ['title', 'description', 'report_date', 'project'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData !== []) {
            $document->update($updateData);

            // Metadata (project/date) can change retrieval scope, invalidate cache.
            KnowledgeChanged::dispatch([$document->id]);
        }

        return $document->fresh();
    }

    /**
     * List documents with optional filtering, sorting, and pagination
     *
     * Returns a paginated array of document records. Supports filtering by status,
     * free-text search (title/original_filename via ILIKE), sorting by allowed keys,
     * and configurable page/per_page pagination. Results are always scoped
     * to the given user.
     *
     * @param  array  $filters  Associative array with optional keys: status, search, sort_key,
     *                          sort_dir, per_page, page. Example: ["status" => "completed", "per_page" => 10]
     * @param  string  $userId  Owner ULID; only their documents are listed. Example: "01J..."
     * @return array Paginated result set with keys: data, current_page, last_page,
     *               per_page, total, from, to.
     *               Example: ["data" => [...], "total" => 42, "current_page" => 1, "per_page" => 20]
     *
     * @throws \InvalidArgumentException If an invalid sort_key or sort_dir is provided
     *                                   Example: $service->listDocuments(["sort_key" => "invalid"]) → defaults to "created_at"
     */
    public function listDocuments(array $filters, string $userId): array
    {
        $query = Document::ownedBy($userId);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.str_replace('%', '\\%', $filters['search']).'%';
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'ilike', $search)
                    ->orWhere('original_filename', 'ilike', $search);
            });
        }

        $sortKey = $filters['sort_key'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $allowedSortKeys = ['title', 'status', 'file_size', 'chunks_count', 'created_at', 'report_date', 'project'];

        if (! in_array($sortKey, $allowedSortKeys, true)) {
            $sortKey = 'created_at';
        }
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortKey, $sortDir);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page)->toArray();
    }

    /**
     * Validate an uploaded file for safety and compatibility
     *
     * Checks: upload success, non-zero size, within max file size, allowed MIME type,
     * extension/MIME consistency, magic bytes for PDF/DOCX, and filename safety
     * (no path traversal characters).
     *
     * @param  UploadedFile  $file  The uploaded file instance to validate. Example: request()->file('file')
     *
     * @throws \RuntimeException If the file upload failed or is corrupted
     *                           Example: $this->validateFile($brokenUpload) → RuntimeException("File upload failed or file is corrupted")
     * @throws \InvalidArgumentException If the file is empty, too large, unsupported type,
     *                                   extension/MIME mismatch, bad magic bytes, or invalid filename
     *                                   Example: $this->validateFile($exeFile) → InvalidArgumentException("File type ... not supported")
     */
    private function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload failed or file is corrupted');
        }

        if ($file->getSize() === 0) {
            throw new \InvalidArgumentException('Uploaded file is empty');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $maxMb = self::MAX_FILE_SIZE / 1024 / 1024;
            throw new \InvalidArgumentException("File size exceeds the {$maxMb}MB limit");
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
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

    /**
     * Map a file extension to its expected MIME type
     *
     * Used internally by validateFile to detect extension/MIME mismatches
     * (e.g., a .pdf file claiming to be image/png).
     *
     * @param  string  $extension  The file extension in lowercase. Example: "pdf"
     * @return string|null The expected MIME type, or null if the extension is unknown.
     *                     Example: "pdf" → "application/pdf", "exe" → null
     */
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

    /**
     * Validate file content matches its extension via magic bytes
     *
     * Reads the first 8 bytes of the file and checks for known magic byte sequences:
     * PDF files must start with "%PDF-", DOCX files must start with the ZIP magic
     * "PK\x03\x04". If the content does not match, an exception is thrown.
     *
     * @param  UploadedFile  $file  The uploaded file instance. Example: request()->file('file')
     * @param  string  $extension  The expected file extension. Example: "pdf"
     *
     * @throws \InvalidArgumentException If the magic bytes don't match the extension
     *                                   Example: $this->validateMagicBytes($textFileWithPdfExtension, "pdf") → InvalidArgumentException
     */
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

    /**
     * Validate that the filename does not contain path-traversal characters
     *
     * Rejects filenames containing "..", "/", or "\\" to prevent directory traversal
     * attacks when the file is stored or referenced.
     *
     * @param  UploadedFile  $file  The uploaded file instance. Example: request()->file('file')
     *
     * @throws \InvalidArgumentException If the filename contains invalid characters
     *                                   Example: $this->validateFilename($fileWithPathTraversal) → InvalidArgumentException("Filename contains invalid characters")
     */
    private function validateFilename(UploadedFile $file): void
    {
        $name = $file->getClientOriginalName();
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException('Filename contains invalid characters');
        }
    }

    /**
     * Load one of the user's documents with its chunk count.
     *
     * @param  string  $id  The ULID of the document. Example: "01J..."
     * @param  string  $userId  Owner ULID. Example: "01J..."
     * @return Document The document with chunks_count loaded
     *
     * @throws ModelNotFoundException If the user owns no such document
     */
    public function getDocument(string $id, string $userId): Document
    {
        return Document::ownedBy($userId)->withCount('chunks')->findOrFail($id);
    }

    /**
     * Find a document owned by the given user.
     *
     * @param  string  $id  The ULID of the document. Example: "01J..."
     * @param  string  $userId  Owner ULID. Example: "01J..."
     *
     * @throws ModelNotFoundException If the user owns no such document
     */
    private function findOwned(string $id, string $userId): Document
    {
        return Document::ownedBy($userId)->findOrFail($id);
    }
}
