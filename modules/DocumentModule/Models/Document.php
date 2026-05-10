<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'user_id',
        'title',
        'original_filename',
        'file_path',
        'file_size',
        'page_count',
        'mime_type',
        'file_hash',
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
            'processed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'document_id');
    }
}
