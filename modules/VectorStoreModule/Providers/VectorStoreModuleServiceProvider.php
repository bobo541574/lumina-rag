<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Providers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use Modules\VectorStoreModule\Services\PgvectorDriver;
use Modules\VectorStoreModule\Services\VectorStoreService;

/**
 * VectorStore Module Service Provider
 *
 * Registers the VectorStore Module's core services into the Laravel container.
 * Binds the PgvectorDriver as a singleton (wrapping the DatabaseManager) and
 * binds the VectorStoreInterface to a VectorStoreService that delegates to the
 * PgvectorDriver.
 *
 * Also loads database migrations for the vector store tables (vector_embeddings
 * and shard tables) when the module is enabled.
 */
class VectorStoreModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services in the container.
     *
     * Creates two singletons:
     * 1. PgvectorDriver — raw pgvector driver wrapping the database
     * 2. VectorStoreInterface — service facade delegating to PgvectorDriver
     */
    public function register(): void
    {
        $this->app->singleton(PgvectorDriver::class, fn ($app): PgvectorDriver => new PgvectorDriver(
            db: $app->make(DatabaseManager::class),
            rrfK: (int) config('rag.search.rrf_k', 60),
        ));

        $this->app->singleton(VectorStoreInterface::class, fn ($app): VectorStoreService => new VectorStoreService(
            driver: $app->make(PgvectorDriver::class),
        ));
    }

    /**
     * Boot module services.
     *
     * Checks if the module is enabled via config and loads migrations
     * for the vector store tables when active.
     */
    public function boot(): void
    {
        if (! config('modules.modules.vector_store.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
