<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Providers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;
use Modules\VectorStoreModule\Services\PgvectorDriver;
use Modules\VectorStoreModule\Services\VectorStoreService;

class VectorStoreModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PgvectorDriver::class, fn ($app): PgvectorDriver => new PgvectorDriver(
            db: $app->make(DatabaseManager::class),
        ));

        $this->app->singleton(VectorStoreInterface::class, fn ($app): VectorStoreService => new VectorStoreService(
            driver: $app->make(PgvectorDriver::class),
        ));
    }

    public function boot(): void
    {
        if (! config('modules.modules.vector_store.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
