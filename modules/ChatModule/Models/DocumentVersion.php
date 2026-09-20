<?php

declare(strict_types=1);

namespace Modules\ChatModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Document Version model
 *
 * Backs a durable per-document generation counter used for fine-grained (Phase 2)
 * semantic cache invalidation. Unlike the coarse global KnowledgeVersion, each
 * document tracks its own version so a change to one document only invalidates
 * cached answers that reference it, leaving unrelated entries valid.
 *
 * Rows are keyed 1:1 by document ULID; `current()` lazily seeds and returns the
 * version, while `bump()` / `bumpMany()` atomically increment it.
 */
class DocumentVersion extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'document_versions';

    protected $fillable = ['document_id', 'version'];

    protected $casts = [
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $model->updated_at = now();
        });
    }

    /**
     * Get the current version for a document, seeding the row if absent.
     *
     * @param  string  $documentId  Document ULID. Example: "01JAR..."
     * @return int Current document version. Example: 3
     */
    public static function current(string $documentId): int
    {
        $row = self::query()->where('document_id', $documentId)->first();
        if ($row === null) {
            $row = self::query()->create(['document_id' => $documentId, 'version' => 1]);

            return 1;
        }

        return (int) $row->version;
    }

    /**
     * Atomically increment the version for a single document.
     *
     * @param  string  $documentId  Document ULID. Example: "01JAR..."
     * @return int New document version. Example: 4
     */
    public static function bump(string $documentId): int
    {
        $row = self::query()->where('document_id', $documentId)->first();
        if ($row === null) {
            // current() seeds the row at version 1, so the first bump is 2.
            self::query()->create(['document_id' => $documentId, 'version' => 2]);

            return 2;
        }

        self::query()->where('document_id', $documentId)->increment('version');

        return (int) $row->version + 1;
    }

    /**
     * Atomically increment the version for a set of documents.
     *
     * @param  array  $documentIds  Document ULIDs. Example: ["01JAR...", "01JAS..."]
     */
    public static function bumpMany(array $documentIds): void
    {
        foreach ($documentIds as $id) {
            self::bump((string) $id);
        }
    }
}
