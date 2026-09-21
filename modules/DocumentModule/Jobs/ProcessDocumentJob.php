<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\DocumentModule\Services\ProcessDocumentService;

/**
 * Process Document Job
 *
 * Thin queue transport wrapper around ProcessDocumentService. All business
 * logic (extraction, chunking, embedding, vector upsert) lives in the service
 * so the same pipeline can be invoked synchronously by console commands,
 * tests, or other services. Runs on the document-processing queue with
 * 3 retries and backoff.
 *
 * @param  string  $documentId  The ULID of the document to process. Example: "01J..."
 */
class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public string $documentId;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [30, 300, 1800];

    public function __construct(string $documentId)
    {
        $this->documentId = $documentId;
        $this->onQueue('document-processing');
    }

    /**
     * Execute the job
     *
     * Delegates to ProcessDocumentService. On any Throwable the service marks
     * the document "failed" and re-throws so the queue worker can manage retries.
     *
     * @param  ProcessDocumentService  $service  The document processing service. Example: app(ProcessDocumentService::class)
     */
    public function handle(ProcessDocumentService $service): void
    {
        $service->process($this->documentId);
    }

    /**
     * Handle job failure after all retries exhausted
     *
     * Re-delegates to the service so failed-state bookkeeping stays in one place.
     *
     * @param  \Throwable  $e  The exception that caused the failure. Example: new RuntimeException("API timeout")
     */
    public function failed(\Throwable $e): void
    {
        app(ProcessDocumentService::class)->markFailed($this->documentId, $e->getMessage());
    }

    /**
     * Get Horizon tags for this job
     *
     * Used by Laravel Horizon for monitoring and filtering.
     *
     * @return array Array of tag strings.
     *               Example: ["ProcessDocumentJob", "document:01J..."]
     */
    public function tags(): array
    {
        return [
            'ProcessDocumentJob',
            "document:{$this->documentId}",
        ];
    }
}
