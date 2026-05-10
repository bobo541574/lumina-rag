<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('document_id');
            $table->longText('content');
            $table->integer('chunk_index');
            $table->integer('page_number')->nullable();
            $table->integer('char_start');
            $table->integer('char_end');
            $table->integer('token_count')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('document_id', 'idx_document_chunks_document_id');
            $table->index(['document_id', 'chunk_index'], 'idx_document_chunks_doc_chunk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
