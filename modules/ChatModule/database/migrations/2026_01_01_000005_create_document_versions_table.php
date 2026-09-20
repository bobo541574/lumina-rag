<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fine-grained (Phase 2) semantic cache invalidation.
     *
     * Adds a durable per-document version counter so a change to one document
     * only invalidates cache entries that reference it, leaving unrelated
     * cached answers valid. Also records, on each cached answer, the document
     * versions it was generated against so a lookup can detect staleness.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('document_id')->unique()->index();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::table('rag_answer_caches', function (Blueprint $table): void {
            $table->jsonb('doc_versions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rag_answer_caches', function (Blueprint $table): void {
            $table->dropColumn('doc_versions');
        });

        Schema::dropIfExists('document_versions');
    }
};
