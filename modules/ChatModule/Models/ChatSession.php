<?php

declare(strict_types=1);

namespace Modules\ChatModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }
}
