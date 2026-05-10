<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title', 255)->default('New Chat');
            $table->ulid('user_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->integer('message_count')->default(0);
            $table->timestampTz('last_activity_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->softDeletesTz();

            $table->index('last_activity_at', 'idx_chat_sessions_activity');
            $table->index('is_archived', 'idx_chat_sessions_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
