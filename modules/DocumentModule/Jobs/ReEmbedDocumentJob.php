<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DocumentModule\Services\ReEmbedDocumentService;

/**
 * Re-Embed Document Job
 *
 * Thin queue transport wrapper around ReEmbedDocumentService. All business
 * logic lives in the service so it can be invoked synchronously as well as via
 * the queue. Used by the rag:reembed artisan command. Runs on the
 * document-processing queue with 3 retries and backoff.
 *
 * @param  string  $documentId  The ULID of the document to re-embed. Example: "01J..."
 */
class ReEmbedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * Delegates to ReEmbedDocumentService.
     *
     * @param  ReEmbedDocumentService  $service  The re-embedding service. Example: app(ReEmbedDocumentService::class)
     */
    public function handle(ReEmbedDocumentService $service): void
    {
        $service->reEmbed($this->documentId);
    }
}
