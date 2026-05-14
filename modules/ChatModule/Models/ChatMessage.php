<?php

declare(strict_types=1);

namespace Modules\ChatModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'chat_messages';

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'token_count',
        'sources',
    ];

    protected function casts(): array
    {
        return [
            'token_count' => 'integer',
            'sources' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('Chat messages are immutable and cannot be modified.');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (): never {
            throw new \RuntimeException('Chat messages are immutable and cannot be modified.');
        });
    }
}
