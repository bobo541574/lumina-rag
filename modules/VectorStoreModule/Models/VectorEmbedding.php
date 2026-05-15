<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class VectorEmbedding extends Model
{
    use HasUlids;

    protected $table = 'vector_embeddings';

    public $timestamps = false;

    protected $fillable = [
        'chunk_id',
        'dimensions',
        'model_name',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
