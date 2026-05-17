<?php

declare(strict_types=1);

namespace Modules\ChatModule\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chat Message Model
 *
 * Represents a single message within a chat session. Messages are immutable
 * once created — calls to update() or any updating event will throw a
 * RuntimeException. Supports soft deletes and ULID primary keys.
 * Sources are stored as a JSONB array containing document references with
 * similarity scores and excerpts.
 *
 * @property string $id ULID primary key
 * @property string $session_id Foreign key to chat_sessions (no DB constraint)
 * @property string $role "user" or "assistant"
 * @property string $content The message text content
 * @property int|null $token_count Approximate token count for the message
 * @property array|null $sources JSON array of source document references
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ChatSession $session
 */
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

    /**
     * Parent session relation
     *
     * Each message belongs to exactly one chat session.
     *
     *
     * @example $message->session->title
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /**
     * Prevent message updates
     *
     * Chat messages are immutable once created. This override ensures that
     * any attempt to call update() on a message instance throws a RuntimeException.
     *
     * @param  array  $attributes  The attributes to update (ignored).
     * @param  array  $options  The options for the update (ignored).
     * @return bool Never returns — always throws.
     *
     * @throws \RuntimeException Always thrown to enforce immutability.
     *                           Example: $message->update(['content' => 'new']) → RuntimeException
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('Chat messages are immutable and cannot be modified.');
    }

    /**
     * Boot the model
     *
     * Registers an `updating` event listener that prevents any modification
     * to existing chat messages by throwing a RuntimeException.
     *
     *
     * @throws \RuntimeException When any update/save attempt is made on an existing message.
     *                           Example: $message->save() → RuntimeException via updating event
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (): never {
            throw new \RuntimeException('Chat messages are immutable and cannot be modified.');
        });
    }
}
