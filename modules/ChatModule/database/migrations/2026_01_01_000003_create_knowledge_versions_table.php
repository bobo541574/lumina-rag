<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durably tracks the knowledge-base generation counter.
     *
     * Need a single persistent row that survives Redis/cache flushes so that
     * cached RAG answers never "rebound" onto a stale corpus. Every document
     * lifecycle event (upload completed, delete, metadata update, re-embed)
     * increments `version`, which invalidates all previously cached answers.
     */
    public function up(): void
    {
        Schema::create('knowledge_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_versions');
    }
};
