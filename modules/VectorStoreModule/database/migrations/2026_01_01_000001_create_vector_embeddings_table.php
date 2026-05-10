<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vector_embeddings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('chunk_id');
            $table->string('model_name', 100);
            $table->string('content_hash', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('chunk_id', 'idx_vector_embeddings_chunk_id');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('vector_embeddings', function (Blueprint $table): void {
                $table->json('embedding')->nullable()->after('chunk_id');
            });

            return;
        }

        $hasVector = false;
        try {
            $extensions = DB::select("SELECT * FROM pg_extension WHERE extname = 'vector'");
            $hasVector = count($extensions) > 0;
        } catch (Throwable) {
            return;
        }

        if (! $hasVector) {
            return;
        }

        $dimensions = (int) config('rag.embedding.dimensions', 1536);

        Schema::table('vector_embeddings', function (Blueprint $table) use ($dimensions): void {
            $table->vector('embedding', $dimensions)->nullable()->after('chunk_id');
        });

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_vector_embeddings_ivfflat ON vector_embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
        } catch (Throwable) {
            // non-blocking — index can be created manually once sufficient data exists
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vector_embeddings');
    }
};
