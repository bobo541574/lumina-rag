<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\ChatModule\Models\ChatSession;
use Modules\DocumentModule\Models\Document;

/**
 * User Model
 *
 * Eloquent model representing a registered user of the RAG system. Uses ULID
 * primary keys, soft-delete-agnostic (relying on app-level enforcement), and
 * token-based authentication via the api_token column.
 *
 * Relationships include chat sessions and uploaded documents. The password is
 * automatically hashed via Laravel's hashed casting. Sensitive fields (password,
 * remember_token, api_token) are hidden from serialization by default.
 *
 * @property string $id ULID primary key. Example: "01JAR9XK4ZK3XK4ZK3XK4ZK3XK"
 * @property string $name User display name. Example: "John Doe"
 * @property string $email User email address. Example: "john@example.com"
 * @property string|null $password Bcrypt-hashed password. Example: "$2y$..."
 * @property string|null $api_token 80-char bearer token. Example: "a1b2c3d4e5..."
 * @property string|null $remember_token Remember-me token. Example: "f6g7h8..."
 * @property Carbon|null $email_verified_at Email verification timestamp. Example: "2026-01-15T10:00:00Z"
 * @property Carbon $created_at Record creation timestamp
 * @property Carbon $updated_at Record update timestamp
 *
 * @method \Illuminate\Database\Eloquent\Relations\HasMany sessions() User's chat sessions
 * @method \Illuminate\Database\Eloquent\Relations\HasMany documents() User's uploaded documents
 *
 * @throws ModelNotFoundException When not found via ULID
 */
#[Fillable(['name', 'email', 'password', 'api_token'])]
#[Hidden(['password', 'remember_token', 'api_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    /**
     * Get the attribute casting configuration.
     *
     * @return array<string, string> Cast map. Example: ["email_verified_at" => "datetime", "password" => "hashed"]
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the chat sessions belonging to this user.
     *
     * @return HasMany Relationship query builder. Example: $user->sessions()->where(...)
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /**
     * Get the documents uploaded by this user.
     *
     * @return HasMany Relationship query builder. Example: $user->documents()->where(...)
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
