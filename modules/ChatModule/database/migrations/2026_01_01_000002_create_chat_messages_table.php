<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('session_id');
            $table->string('role', 20);
            $table->longText('content');
            $table->integer('token_count')->nullable();
            $table->jsonb('sources')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->softDeletesTz();

            $table->index('session_id', 'idx_chat_messages_session_id');
            $table->index(['session_id', 'created_at'], 'idx_chat_messages_session_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
