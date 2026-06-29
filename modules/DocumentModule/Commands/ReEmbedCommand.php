<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\DocumentModule\Jobs\ReEmbedDocumentJob;
use Modules\DocumentModule\Models\Document;

class ReEmbedCommand extends Command
{
    protected $signature = 'rag:reembed
        {--document= : Re-embed a specific document by ULID}
        {--missing : Only re-embed completed documents that have chunks with no vector rows}
        {--only-old-model : Only re-embed documents whose existing embeddings use a different model than the current config}
        {--status= : Only re-embed documents with a specific status (e.g., completed, failed)}
        {--project= : Filter by project name (e.g., "Orion")}
        {--report-date= : Filter by report date — exact (2026-04-30), month (2026-04), or range (2026-04-01:2026-04-30)}
        {--dry-run : Print affected documents without dispatching any jobs}
        {--force : Include documents of any status (not just completed) when used with --missing}';

    protected $description = 'Re-embed all chunked documents with the current embedding model';

    public function handle(): int
    {
        $documentId = $this->option('document');

        if ($documentId !== null) {
            return $this->handleSingle($documentId);
        }

        if ($this->option('missing')) {
            return $this->handleMissing();
        }

        return $this->handleBatch();
    }

    private function handleSingle(string $documentId): int
    {
        $document = Document::find($documentId);

        if ($document === null) {
            $this->error("Document not found: {$documentId}");

            return self::FAILURE;
        }

        if ($document->chunks()->count() === 0) {
            $this->warn("Document '{$document->title}' has no chunks. Nothing to re-embed.");

            return self::SUCCESS;
        }

        ReEmbedDocumentJob::dispatch($documentId);
        $this->info("Dispatched ReEmbedDocumentJob for document: {$document->title} ({$documentId})");

        return self::SUCCESS;
    }

    /**
     * Apply --project and --report-date filters to a query builder.
     *
     * --report-date formats:
     *   2026-04-30            → exact date
     *   2026-04               → full month (2026-04-01 to 2026-04-30)
     *   2026-04-01:2026-04-30 → explicit range
     */
    private function applyCommonFilters(Builder $query): void
    {
        $project = $this->option('project');
        if ($project !== null) {
            $query->where('project', $project);
        }

        $reportDate = $this->option('report-date');
        if ($reportDate !== null) {
            if (str_contains($reportDate, ':')) {
                [$from, $to] = explode(':', $reportDate, 2);
                $query->whereBetween('report_date', [trim($from), trim($to)]);
            } elseif (preg_match('/^\d{4}-\d{2}$/', $reportDate)) {
                $query->whereYear('report_date', substr($reportDate, 0, 4))
                    ->whereMonth('report_date', substr($reportDate, 5, 2));
            } else {
                $query->where('report_date', $reportDate);
            }
        }
    }

    private function handleMissing(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // Find completed documents that have at least one chunk with no vector row.
        // We check all ve_* shard tables and fall back to vector_embeddings on SQLite.
        $isSqlite = DB::getDriverName() === 'sqlite';

        $query = Document::query()->whereNull('deleted_at');

        if (! $force) {
            $query->where('status', 'completed');
        }

        $this->applyCommonFilters($query);

        $query->whereHas('chunks', function ($q) use ($isSqlite): void {
            if ($isSqlite) {
                $q->whereNotExists(function ($sub): void {
                    $sub->selectRaw(1)
                        ->from('vector_embeddings')
                        ->whereColumn('vector_embeddings.chunk_id', 'document_chunks.id');
                });
            } else {
                $q->whereNotExists(function ($sub): void {
                    $sub->selectRaw(1)
                        ->fromRaw('(SELECT chunk_id FROM ve_384 UNION ALL SELECT chunk_id FROM ve_768 UNION ALL SELECT chunk_id FROM ve_1024 UNION ALL SELECT chunk_id FROM ve_1536 UNION ALL SELECT chunk_id FROM ve_3072) AS ve_all')
                        ->whereColumn('ve_all.chunk_id', 'document_chunks.id');
                });
            }
        });

        $documents = $query->get(['id', 'title', 'status']);

        if ($documents->isEmpty()) {
            $this->info('No documents with missing vectors found.');

            return self::SUCCESS;
        }

        $this->info("Found {$documents->count()} document(s) with missing vectors:");
        foreach ($documents as $doc) {
            $this->line("  [{$doc->status}] {$doc->title} ({$doc->id})");
        }

        if ($dryRun) {
            $this->warn('Dry-run mode: no jobs dispatched.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        foreach ($documents as $document) {
            ReEmbedDocumentJob::dispatch($document->id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Re-embed jobs dispatched.');

        return self::SUCCESS;
    }

    private function handleBatch(): int
    {
        $onlyOldModel = $this->option('only-old-model');
        $status = $this->option('status');
        $dryRun = (bool) $this->option('dry-run');

        $query = Document::query();

        if ($status !== null) {
            $query->where('status', $status);
        }

        $this->applyCommonFilters($query);

        if ($onlyOldModel) {
            $currentModel = config('rag.embedding.model', 'text-embedding-ada-002');

            $query->whereExists(function ($q) use ($currentModel): void {
                $q->selectRaw(1)
                    ->from('document_chunks')
                    ->leftJoin('vector_embeddings', 'vector_embeddings.chunk_id', '=', 'document_chunks.id')
                    ->whereColumn('document_chunks.document_id', 'documents.id')
                    ->where(function ($sub) use ($currentModel): void {
                        $sub->whereNull('vector_embeddings.id')
                            ->orWhere('vector_embeddings.model_name', '!=', $currentModel);
                    });
            });
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->warn('No documents found to re-embed.');

            return self::SUCCESS;
        }

        $this->info("Found {$documents->count()} document(s) to re-embed.");

        if ($dryRun) {
            foreach ($documents as $doc) {
                $this->line("  [{$doc->status}] {$doc->title} ({$doc->id})");
            }
            $this->warn('Dry-run mode: no jobs dispatched.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        foreach ($documents as $document) {
            ReEmbedDocumentJob::dispatch($document->id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('All jobs dispatched successfully.');

        return self::SUCCESS;
    }
}
