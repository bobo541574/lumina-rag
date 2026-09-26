<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\DocumentModule\Enums\DocumentStatus;
use Modules\DocumentModule\Jobs\ReEmbedDocumentJob;
use Modules\DocumentModule\Models\Document;

class ReEmbedCommand extends Command
{
    protected $signature = 'rag:reembed
        {--document= : Re-embed a specific document by ULID}
        {--missing : Only re-embed completed documents that have chunks with no vector rows}
        {--only-old-model : Only re-embed documents whose vectors were made with a different model than the model configured for that document}
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
        $query = Document::query();

        if (! $this->option('force')) {
            $query->where('status', DocumentStatus::Completed);
        }

        $this->applyCommonFilters($query);

        // vector_embeddings holds one metadata row per stored vector on every
        // driver (the pgvector shards hold the vectors themselves), so it is
        // the single place to look for missing vectors.
        $query->whereHas('chunks', fn ($chunks) => $chunks->whereNotExists(
            fn ($sub) => $sub->selectRaw(1)
                ->from('vector_embeddings')
                ->whereColumn('vector_embeddings.chunk_id', 'document_chunks.id'),
        ));

        return $this->dispatchFor($query->get(['id', 'title', 'status']), 'No documents with missing vectors found.');
    }

    private function handleBatch(): int
    {
        $query = Document::query()->whereHas('chunks');

        $status = $this->option('status');
        if ($status !== null) {
            if (DocumentStatus::tryFrom($status) === null) {
                $this->error("Unknown status '{$status}'. Use one of: ".implode(', ', array_column(DocumentStatus::cases(), 'value')));

                return self::FAILURE;
            }
            $query->where('status', $status);
        }

        $this->applyCommonFilters($query);

        if ($this->option('only-old-model')) {
            // Compare each document's vectors with the model that document is
            // configured for, falling back to the global default model.
            $defaultModel = (string) config('rag.embedding.model');

            $query->whereExists(fn ($q) => $q->selectRaw(1)
                ->from('document_chunks')
                ->leftJoin('vector_embeddings', 'vector_embeddings.chunk_id', '=', 'document_chunks.id')
                ->whereColumn('document_chunks.document_id', 'documents.id')
                ->where(fn ($sub) => $sub->whereNull('vector_embeddings.id')
                    ->orWhereRaw('vector_embeddings.model_name <> COALESCE(documents.embedding_model, ?)', [$defaultModel])));
        }

        return $this->dispatchFor($query->get(['id', 'title', 'status']), 'No documents found to re-embed.');
    }

    /**
     * List the documents and, unless --dry-run, queue a re-embed job for each.
     *
     * @param  Collection<int, Document>  $documents  Documents to re-embed
     * @param  string  $emptyMessage  Shown when there is nothing to do. Example: "No documents found to re-embed."
     * @return int Command exit code
     */
    private function dispatchFor($documents, string $emptyMessage): int
    {
        if ($documents->isEmpty()) {
            $this->info($emptyMessage);

            return self::SUCCESS;
        }

        $this->info("Found {$documents->count()} document(s) to re-embed:");
        foreach ($documents as $doc) {
            $this->line("  [{$doc->status->value}] {$doc->title} ({$doc->id})");
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode: no jobs dispatched.');

            return self::SUCCESS;
        }

        $this->withProgressBar($documents, fn (Document $document) => ReEmbedDocumentJob::dispatch($document->id));
        $this->newLine();
        $this->info('Re-embed jobs dispatched.');

        return self::SUCCESS;
    }
}
