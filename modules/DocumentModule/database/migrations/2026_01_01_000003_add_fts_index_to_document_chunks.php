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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->tsvector('tsv_content')->nullable()->after('metadata');
        });

        DB::statement('CREATE INDEX IF NOT EXISTS idx_chunks_tsv ON document_chunks USING gin (tsv_content)');

        DB::statement('UPDATE document_chunks SET tsv_content = to_tsvector(\'simple\', coalesce(content, \'\'))');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_chunks_tsv');

        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->dropColumn('tsv_content');
        });
    }
};
