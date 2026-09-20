<?php

declare(strict_types=1);

namespace Modules\ChatModule\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Knowledge Version model
 *
 * Backs the durable knowledge-base generation counter. The counter lives in
 * the database (not the cache store) so it survives cache/Redis flushes and
 * never resets, guaranteeing that cached RAG answers cannot "rebound" onto a
 * stale corpus after invalidation.
 *
 * The model wraps a singleton row; `current()` lazily seeds it and returns the
 * current version, while `bump()` atomically increments it.
 */
class KnowledgeVersion extends Model
{
    public $timestamps = false;

    protected $table = 'knowledge_versions';

    protected $fillable = ['version'];

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
     * Get the current knowledge version, seeding the singleton row if absent.
     *
     * @return int The current knowledge version. Example: 6
     */
    public static function current(): int
    {
        $row = self::query()->first();
        if ($row === null) {
            $row = self::query()->create(['version' => 1]);
        }

        return (int) $row->version;
    }

    /**
     * Atomically increment the knowledge version by one.
     *
     * Uses a single UPDATE ... RETURNING so concurrent document events cannot
     * lose increments. Returns the new version value.
     *
     * @return int The new knowledge version. Example: 7
     */
    public static function bump(): int
    {
        // Ensure the singleton row exists before incrementing.
        $row = self::query()->first();
        if ($row === null) {
            $row = self::query()->create(['version' => 1]);

            return 1;
        }

        $newVersion = (int) $row->version + 1;
        self::query()->where('id', $row->id)->update(['version' => $newVersion]);

        return $newVersion;
    }
}
