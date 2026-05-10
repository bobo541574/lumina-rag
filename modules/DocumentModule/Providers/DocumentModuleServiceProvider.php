<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\DocumentModule\Commands\ReEmbedCommand;
use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;
use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use Modules\DocumentModule\Services\DocumentService;
use Modules\DocumentModule\Services\TextChunkingService;
use Modules\DocumentModule\Services\TextExtractionService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

class DocumentModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TextExtractionServiceInterface::class, TextExtractionService::class);
        $this->app->singleton(TextChunkingServiceInterface::class, TextChunkingService::class);

        $this->app->singleton(DocumentService::class, fn ($app): DocumentService => new DocumentService(
            extractor: $app->make(TextExtractionServiceInterface::class),
            chunker: $app->make(TextChunkingServiceInterface::class),
            embedder: $app->make(EmbeddingServiceInterface::class),
            vectorStore: $app->make(VectorStoreInterface::class),
            events: $app->make(Dispatcher::class),
            chunkSize: (int) config('rag.chunking.chunk_size', 1000),
            overlap: (int) config('rag.chunking.overlap', 200),
            batchSize: (int) config('rag.embedding.batch_size', 100),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/document.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReEmbedCommand::class,
            ]);
        }
    }
}
