<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->date('report_date')->nullable()->after('description');
            $table->string('project', 255)->nullable()->after('report_date');
            $table->index('project');
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['project']);
            $table->dropIndex(['report_date']);
            $table->dropColumn(['report_date', 'project']);
        });
    }
};
