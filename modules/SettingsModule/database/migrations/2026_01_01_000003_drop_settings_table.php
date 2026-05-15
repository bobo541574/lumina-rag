<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('settings');
    }

    public function down(): void
    {
        // Re-create is not needed — settings data was ephemeral config overrides
    }
};
