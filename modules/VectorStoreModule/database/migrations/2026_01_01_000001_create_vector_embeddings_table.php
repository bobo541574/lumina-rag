<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\VectorStoreModule\Support\VectorShardSchema;

return new class extends Migration
{
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

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('vector_embeddings', function (Blueprint $table): void {
                $table->json('embedding')->nullable()->after('chunk_id');
            });

            return;
        }

        VectorShardSchema::ensure();
    }

    public function down(): void
    {
        VectorShardSchema::drop();
        Schema::dropIfExists('vector_embeddings');
    }
};
