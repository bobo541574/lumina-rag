<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Commands;

use Illuminate\Console\Command;
use Modules\DocumentModule\Jobs\ReEmbedDocumentJob;
use Modules\DocumentModule\Models\Document;

class ReEmbedCommand extends Command
{
    protected $signature = 'rag:reembed
        {--document= : Re-embed a specific document by ULID}
        {--only-old-model : Only re-embed documents whose existing embeddings use a different model than the current config}
        {--status= : Only re-embed documents with a specific status (e.g., completed, failed)}';

    protected $description = 'Re-embed all chunked documents with the current embedding model';

    public function handle(): int
    {
        $documentId = $this->option('document');

        if ($documentId !== null) {
            return $this->handleSingle($documentId);
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

    private function handleBatch(): int
    {
        $onlyOldModel = $this->option('only-old-model');
        $status = $this->option('status');

        $query = Document::query();

        if ($status !== null) {
            $query->where('status', $status);
        }

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

        $this->info("Dispatching ReEmbedDocumentJob for {$documents->count()} document(s)...");

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
