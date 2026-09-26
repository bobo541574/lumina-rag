<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\EmbeddingService;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\SettingsModule\Models\AiModel;

/**
 * Embedding Module Service Provider
 *
 * Registers the Embedding Module's core services into the Laravel container.
 * Registers ProviderFactory, binds the EmbeddingProviderInterface to a
 * fallback provider built by it from `AiModel::fromConfig('embedding')`
 * (config/rag.php), and binds the EmbeddingServiceInterface to the caching
 * EmbeddingService wrapper.
 */
class EmbeddingModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services in the container.
     *
     * Creates three singletons:
     * 1. ProviderFactory — factory for creating providers per AiModel config
     * 2. EmbeddingProviderInterface — fallback provider built from config/rag.php
     * 3. EmbeddingServiceInterface — caching wrapper around the provider
     */
    public function register(): void
    {
        // Fallback provider when no embedding AiModel is configured; built through
        // ProviderFactory so config-based and DB-based models share one code path.
        $this->app->singleton(ProviderFactory::class);

        $this->app->singleton(EmbeddingProviderInterface::class, fn ($app): EmbeddingProviderInterface => $app
            ->make(ProviderFactory::class)
            ->createEmbeddingProvider(AiModel::fromConfig('embedding')));

        $this->app->singleton(EmbeddingServiceInterface::class, fn ($app): EmbeddingService => new EmbeddingService(
            provider: $app->make(EmbeddingProviderInterface::class),
            cache: $app->make(CacheRepository::class),
            cacheTtl: (int) config('rag.embedding.cache_ttl', 86400),
        ));
    }

    /**
     * Boot module services.
     *
     * Checks if the module is enabled via config and returns early if not.
     * No additional boot-time actions are currently performed.
     */
    public function boot(): void
    {
        if (! config('modules.modules.embedding.enabled', true)) {
            return;
        }
    }
}
