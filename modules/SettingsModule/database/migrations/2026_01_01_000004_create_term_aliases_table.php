<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 50)->default('general'); // project, technical, general
            $table->string('alias', 255);
            $table->string('canonical', 255);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['alias', 'canonical']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_aliases');
    }
};
