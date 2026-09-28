<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebuild the jobs and failed_jobs tables with auto-incrementing ids.
     *
     * The framework's queue providers write these tables via insert() without
     * supplying an id (DatabaseQueue::pushToDatabase uses insertGetId; the
     * database-uuids failed-job driver uses plain insert()), relying on the
     * stock auto-increment primary key. An earlier version of the stock jobs
     * migration switched these columns to ULID primary keys, which have no
     * server-side default — every failed job then crashed with a NOT NULL
     * violation on the id column. Both tables carry only transient queued/failed
     * payloads, so rebuilding them loses no persistent data.
     */
    public function up(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::dropIfExists('job_batches');
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    /**
     * Drop the rebuilt tables.
     *
     * The framework can never persist queue records against the earlier ULID
     * primary keys, so on rollback there is nothing of theirs worth restoring.
     * A subsequent migrate recreates the stock schema from the base migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
    }
};
