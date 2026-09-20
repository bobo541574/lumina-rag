<?php

declare(strict_types=1);

namespace Modules\ChatModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * RagAnswerCache model
 *
 * Represents a single semantic-cache entry: a previously-generated RAG answer
 * bound to the query embedding vector and the knowledge version it was produced
 * against. Used by SemanticCacheService for hit detection and persistence.
 */
class RagAnswerCache extends Model
{
    use HasUlids;

    protected $table = 'rag_answer_caches';

    protected $fillable = [
        'version',
        'user_id',
        'query_vector',
        'question',
        'answer',
        'sources',
        'model_hash',
        'document_ids',
        'doc_versions',
        'is_refusal',
    ];

    protected $casts = [
        'version' => 'integer',
        'sources' => 'array',
        'document_ids' => 'array',
        'doc_versions' => 'array',
        'is_refusal' => 'boolean',
    ];
}
