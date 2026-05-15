<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 255);
            $table->string('type', 20); // 'embedding' or 'llm'
            $table->string('provider', 50); // 'openai', 'ollama'
            $table->string('model', 255); // actual API model identifier
            $table->text('api_key')->nullable();
            $table->string('base_url', 500)->nullable();
            $table->string('collection', 100)->nullable(); // maps to ve_{dim} vector table

            // Embedding-specific
            $table->integer('dimensions')->nullable();
            $table->integer('batch_size')->nullable();
            $table->integer('cache_ttl')->nullable();

            // LLM-specific
            $table->decimal('temperature', 4, 2)->nullable();
            $table->integer('max_context_tokens')->nullable();

            // Common
            $table->integer('timeout')->default(30);

            // Description for user decision-making
            $table->text('description')->nullable();

            // Extended configuration (search, chunking, chat, vector store, logging)
            $table->jsonb('settings')->nullable();

            // Metadata
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index('type', 'idx_ai_models_type');
            $table->index('is_active', 'idx_ai_models_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
