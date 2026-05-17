<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\SettingsModule\Models\AiModel;

/**
 * Document Model
 *
 * Represents an uploaded document file in the system. Tracks the full lifecycle
 * from upload through processing to completion. Each document has many
 * DocumentChunk records and belongs to a user and optionally an AiModel
 * (for per-document embedding model overrides). Uses ULID primary keys and
 * soft deletes.
 *
 * @property string $id The ULID primary key. Example: "01J..."
 * @property string|null $user_id The owning user's ULID. Example: "01J..."
 * @property string $title Human-readable document title. Example: "Q3 Report"
 * @property string|null $description Optional description. Example: "Quarterly financial report"
 * @property string|null $report_date Optional report date (Y-m-d). Example: "2026-05-01"
 * @property string|null $project Optional project grouping. Example: "Orion"
 * @property string $original_filename Original uploaded filename. Example: "report.pdf"
 * @property string $file_path Storage path on disk. Example: "documents/abc123.pdf"
 * @property int $file_size File size in bytes. Example: 2456789
 * @property int|null $page_count Number of pages (PDF only). Example: 42
 * @property string $mime_type MIME type. Example: "application/pdf"
 * @property string $file_hash SHA-256 hash for deduplication. Example: "e3b0c442..."
 * @property string|null $embedding_model Embedding model name used. Example: "text-embedding-3-small"
 * @property string|null $embedding_model_id FK to ai_models table. Example: "01J..."
 * @property string $status Processing status: pending|processing|completed|failed. Example: "completed"
 * @property int|null $chunks_count Number of chunks created. Example: 15
 * @property string|null $error_message Error message if failed. Example: "File not found"
 * @property string|null $processed_at Timestamp when processing completed. Example: "2026-05-17T12:00:00Z"
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'report_date',
        'project',
        'original_filename',
        'file_path',
        'file_size',
        'page_count',
        'mime_type',
        'file_hash',
        'embedding_model',
        'embedding_model_id',
        'status',
        'chunks_count',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'page_count' => 'integer',
            'chunks_count' => 'integer',
            'description' => 'string',
            'report_date' => 'date:Y-m-d',
            'processed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the text chunks belonging to this document
     *
     * A document may have zero or more DocumentChunk records created during
     * processing. Chunks are ordered by their chunk_index.
     *
     * @return HasMany The relationship to DocumentChunk models.
     *                 Example: $document->chunks()->count() → 15
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'document_id');
    }

    /**
     * Get the AI model used for embedding this document
     *
     * Each document can optionally specify a custom embedding model via the
     * ai_models registry. If null, the global default from config is used.
     *
     * @return BelongsTo The relationship to the AiModel.
     *                   Example: $document->embeddingModel?->model → "text-embedding-3-small"
     */
    public function embeddingModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'embedding_model_id');
    }
}
