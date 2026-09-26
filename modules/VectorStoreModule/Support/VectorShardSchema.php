<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the pgvector shard tables (ve_{dims}) that hold the embeddings.
 *
 * vector_embeddings only stores metadata on PostgreSQL; without these tables
 * no vector can be stored or searched, so a missing pgvector extension is an
 * error rather than something to skip silently.
 */
final class VectorShardSchema
{
    /** @var array<int> Supported embedding dimensions, one table each */
    public const DIMENSIONS = [384, 768, 1024, 1536, 3072];

    /**
     * Enable pgvector and create any missing shard tables. Idempotent;
     * a no-op on non-PostgreSQL connections.
     *
     * @throws \RuntimeException When the pgvector extension cannot be enabled
     */
    public static function ensure(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'pgvector is required on PostgreSQL. Install it and run "CREATE EXTENSION vector;" as a superuser, then migrate again. '
                .'Cause: '.$e->getMessage(),
                previous: $e,
            );
        }

        $lists = (int) config('rag.vector_store.index_lists', 100);

        foreach (self::DIMENSIONS as $dim) {
            $table = "ve_{$dim}";
            if (Schema::hasTable($table)) {
                continue;
            }

            Schema::create($table, function (Blueprint $t) use ($dim, $table): void {
                $t->ulid('id')->primary();
                $t->ulid('chunk_id');
                $t->vector('embedding', $dim);
                $t->string('model_name', 100);
                $t->string('content_hash', 32);
                $t->timestampTz('created_at')->useCurrent();

                $t->index('chunk_id', "idx_{$table}_chunk_id");
            });

            // ivfflat supports at most 2000 dimensions.
            if ($dim <= 2000) {
                DB::statement("CREATE INDEX IF NOT EXISTS idx_{$table}_ivfflat ON {$table} USING ivfflat (embedding vector_cosine_ops) WITH (lists = {$lists})");
            }
        }
    }

    /**
     * Drop all shard tables.
     */
    public static function drop(): void
    {
        foreach (self::DIMENSIONS as $dim) {
            Schema::dropIfExists("ve_{$dim}");
        }
    }
}
