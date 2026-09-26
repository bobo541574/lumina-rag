<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\VectorStoreModule\Support\VectorShardSchema;

/**
 * Databases migrated before pgvector was enabled have vector_embeddings but no
 * ve_* shard tables, so nothing could be embedded. Create them if missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        VectorShardSchema::ensure();
    }

    public function down(): void
    {
        // Shard tables are owned by the create_vector_embeddings_table migration.
    }
};
