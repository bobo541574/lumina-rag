<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\DocumentModule\Commands\GenerateTemplatesCommand;
use Modules\DocumentModule\Commands\ReEmbedCommand;
use Modules\DocumentModule\Contracts\DocumentServiceInterface;
use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;
use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use Modules\DocumentModule\Services\DocumentService;
use Modules\DocumentModule\Services\TextChunkingService;
use Modules\DocumentModule\Services\TextExtractionService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Document Module Service Provider
 *
 * Registers and bootstraps the DocumentModule within the Laravel application.
 * Binds three service interfaces to their concrete implementations as singletons:
 * TextExtractionServiceInterface, TextChunkingServiceInterface, and
 * DocumentServiceInterface (which depends on the other two plus EmbeddingService
 * and VectorStore). During boot, loads migrations and routes if the module is
 * enabled in config, and registers console commands when running in CLI.
 *
 * @throws \RuntimeException If required dependencies cannot be resolved from the container
 */
class DocumentModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services in the container
     *
     * Binds TextExtractionServiceInterface → TextExtractionService (singleton).
     * Binds TextChunkingServiceInterface → TextChunkingService (singleton).
     * Binds DocumentServiceInterface → DocumentService (singleton) with injected
     * dependencies and config-driven defaults for chunk size, overlap, and batch size.
     */
    public function register(): void
    {
        $this->app->singleton(TextExtractionServiceInterface::class, TextExtractionService::class);
        $this->app->singleton(TextChunkingServiceInterface::class, TextChunkingService::class);

        $this->app->singleton(DocumentServiceInterface::class, fn ($app): DocumentService => new DocumentService(
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

    /**
     * Boot module services
     *
     * Checks if the module is enabled via config (modules.modules.document.enabled).
     * If enabled, loads migration files from the module's database/migrations directory
     * and routes from Routes/document.php. Registers console commands (ReEmbedCommand,
     * GenerateTemplatesCommand) when running in the console.
     */
    public function boot(): void
    {
        if (! config('modules.modules.document.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/document.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReEmbedCommand::class,
                GenerateTemplatesCommand::class,
            ]);
        }
    }
}
