<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
