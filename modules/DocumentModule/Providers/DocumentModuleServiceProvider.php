<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\DocumentModule\Commands\GenerateTemplatesCommand;
use Modules\DocumentModule\Commands\ReEmbedCommand;
use Modules\DocumentModule\Contracts\DocumentServiceInterface;
use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;
use Modules\DocumentModule\Contracts\TextExtractionServiceInterface;
use Modules\DocumentModule\Services\DocumentService;
use Modules\DocumentModule\Services\TextChunkingService;
use Modules\DocumentModule\Services\TextExtractionService;

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
     * Binds DocumentServiceInterface → DocumentService (singleton).
     */
    public function register(): void
    {
        $this->app->singleton(TextExtractionServiceInterface::class, TextExtractionService::class);
        $this->app->singleton(TextChunkingServiceInterface::class, TextChunkingService::class);

        $this->app->singleton(DocumentServiceInterface::class, DocumentService::class);
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
