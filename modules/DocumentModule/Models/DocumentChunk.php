<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentChunk Model
 *
 * Represents a single chunk of text extracted from an uploaded document. Each chunk
 * stores the content, its positional metadata (character range, page number, index),
 * and an estimated token count. Chunks are the atomic unit for embedding generation
 * and vector search. This model does not use timestamps (no created_at/updated_at).
 * Uses ULID primary keys.
 *
 * @property string $id The ULID primary key. Example: "01J..."
 * @property string $document_id FK to documents table. Example: "01J..."
 * @property string $content The chunk text content (may include a metadata header). Example: "[Section 1]\nReport text..."
 * @property int $chunk_index Zero-based position within the document. Example: 0
 * @property int|null $page_number Source page number (PDF only). Example: 3
 * @property int $char_start Start character offset in original text. Example: 0
 * @property int $char_end End character offset in original text. Example: 1000
 * @property int|null $token_count Estimated token count (char_count / 4). Example: 250
 * @property array|null $metadata Optional JSON metadata. Example: {"section": "Introduction"}
 */
class DocumentChunk extends Model
{
    use HasUlids;

    protected $table = 'document_chunks';

    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'content',
        'chunk_index',
        'page_number',
        'char_start',
        'char_end',
        'token_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'page_number' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'token_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the parent document for this chunk
     *
     * Each chunk belongs to exactly one document record.
     *
     * @return BelongsTo The relationship to the Document model.
     *                   Example: $chunk->document->title → "Q3 Report"
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
