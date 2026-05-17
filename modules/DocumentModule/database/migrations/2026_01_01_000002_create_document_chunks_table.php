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
        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('document_id');
            $table->longText('content');
            $table->integer('chunk_index');
            $table->integer('page_number')->nullable();
            $table->integer('char_start');
            $table->integer('char_end');
            $table->integer('token_count')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('document_id', 'idx_document_chunks_document_id');
            $table->index(['document_id', 'chunk_index'], 'idx_document_chunks_doc_chunk');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE document_chunks ADD COLUMN tsv_content tsvector');
            DB::statement("UPDATE document_chunks SET tsv_content = to_tsvector('english', coalesce(content, '')) WHERE tsv_content IS NULL");
            DB::statement('CREATE INDEX IF NOT EXISTS idx_chunks_tsv ON document_chunks USING gin (tsv_content)');

            DB::statement('CREATE INDEX IF NOT EXISTS idx_document_chunks_metadata_gin ON document_chunks USING gin (metadata jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
