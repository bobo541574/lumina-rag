<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->nullable();
            $table->string('title', 255);
            $table->string('original_filename', 255);
            $table->string('file_path', 500);
            $table->integer('file_size');
            $table->integer('page_count')->nullable();
            $table->string('mime_type', 100);
            $table->string('file_hash', 64)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending');
            $table->integer('chunks_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->softDeletesTz();

            $table->index('status', 'idx_documents_status');
            $table->index('file_hash', 'idx_documents_file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
