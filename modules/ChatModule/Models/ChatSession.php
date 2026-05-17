<?php

declare(strict_types=1);

namespace Modules\ChatModule\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chat Session Model
 *
 * Represents a conversational session containing multiple chat messages.
 * Sessions track ownership (user_id), display title, archival status, and
 * last activity timestamp. Supports soft deletes and ULID primary keys.
 * Provides query scopes for filtering active, expired (24h idle), and
 * stale (30d idle) sessions for cleanup workflows.
 *
 * @property string $id ULID primary key
 * @property string|null $user_id Owner ULID (foreign key to users table, no DB constraint)
 * @property string $title Session title (auto-derived from first assistant message)
 * @property bool $is_archived Whether the session is archived
 * @property int $message_count Running count of messages in the session
 * @property Carbon|null $last_activity_at Timestamp of last activity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Collection|ChatMessage[] $messages
 */
class ChatSession extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'chat_sessions';

    protected $fillable = [
        'user_id',
        'title',
        'is_archived',
        'message_count',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'message_count' => 'integer',
            'last_activity_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Messages relation
     *
     * A session has many chat messages, ordered by creation order.
     *
     *
     * @example $session->messages()->where('role', 'assistant')->get()
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }

    /**
     * Scope: active sessions
     *
     * Filters sessions that are not soft-deleted and have had activity
     * within the last 24 hours.
     *
     * @param  Builder  $query  The Eloquent query builder instance.
     *
     * @example ChatSession::active()->get()
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deleted_at')
            ->where('last_activity_at', '>=', now()->subHours(24));
    }

    /**
     * Scope: expired sessions
     *
     * Filters sessions that are not soft-deleted but have been inactive
     * for more than 24 hours.
     *
     * @param  Builder  $query  The Eloquent query builder instance.
     *
     * @example ChatSession::expired()->get()
     */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNull('deleted_at')
            ->where('last_activity_at', '<', now()->subHours(24));
    }

    /**
     * Scope: stale sessions
     *
     * Filters sessions that are not soft-deleted but have been inactive
     * for more than 30 days. Used by the cleanup command.
     *
     * @param  Builder  $query  The Eloquent query builder instance.
     *
     * @example ChatSession::stale()->get()
     */
    public function scopeStale(Builder $query): void
    {
        $query->whereNull('deleted_at')
            ->where('last_activity_at', '<', now()->subDays(30));
    }
}
