<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semantic (answer-level) RAG cache.
     *
     * Stores the final grounded answer produced for a question, keyed by the
     * query embedding vector, the knowledge version at write time, the user,
     * and the LLM configuration hash. Subsequent semantically-equivalent
     * questions are matched by cosine similarity against query_vector and may
     * bypass retrieval + LLM generation entirely.
     *
     * Storing the query vector (as JSON text so it works on both pgvector and
     * SQLite test setups) is metadata-only here; matching is performed in-memory,
     * mirroring the VectorStoreModule's approach.
     */
    public function up(): void
    {
        Schema::create('rag_answer_caches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
            $table->ulid('user_id')->nullable()->index();
            $table->text('query_vector')->nullable();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->jsonb('sources')->nullable();
            $table->string('model_hash', 64)->nullable()->index();
            $table->jsonb('document_ids')->nullable();
            $table->boolean('is_refusal')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['version', 'user_id'], 'idx_rag_answer_cache_version_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_answer_caches');
    }
};
