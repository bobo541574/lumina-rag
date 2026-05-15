<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function getPgsqlVectorDims(): array
    {
        return [384, 768, 1024, 1536, 3072];
    }

    public function up(): void
    {
        Schema::create('vector_embeddings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('chunk_id');
            $table->integer('dimensions');
            $table->string('model_name', 100);
            $table->string('content_hash', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('chunk_id', 'idx_vector_embeddings_chunk_id');
            $table->index('dimensions', 'idx_vector_embeddings_dims');
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

        $lists = (int) config('rag.vector_store.index_lists', 100);

        foreach ($this->getPgsqlVectorDims() as $dim) {
            $tableName = "ve_{$dim}";

            Schema::create($tableName, function (Blueprint $table) use ($dim, $tableName): void {
                $table->ulid('id')->primary();
                $table->ulid('chunk_id');
                $table->vector('embedding', $dim);
                $table->string('model_name', 100);
                $table->string('content_hash', 32);
                $table->timestampTz('created_at')->useCurrent();

                $table->index('chunk_id', "idx_{$tableName}_chunk_id");
            });

            if ($dim <= 2000) {
                DB::statement(
                    "CREATE INDEX IF NOT EXISTS idx_{$tableName}_ivfflat ON {$tableName} USING ivfflat (embedding vector_cosine_ops) WITH (lists = {$lists})"
                );
            }
        }
    }

    public function down(): void
    {
        foreach ($this->getPgsqlVectorDims() as $dim) {
            Schema::dropIfExists("ve_{$dim}");
        }

        Schema::dropIfExists('vector_embeddings');
    }
};
