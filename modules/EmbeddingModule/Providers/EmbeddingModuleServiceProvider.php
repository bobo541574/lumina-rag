<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\EmbeddingService;
use Modules\EmbeddingModule\Services\OllamaEmbeddingProvider;
use Modules\EmbeddingModule\Services\OpenAIEmbeddingProvider;

class EmbeddingModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmbeddingProviderInterface::class, function (): EmbeddingProviderInterface {
            $provider = (string) config('rag.embedding.provider', 'openai');

            return match ($provider) {
                'ollama' => new OllamaEmbeddingProvider(
                    baseUrl: (string) config('rag.embedding.base_url', 'http://localhost:11434'),
                    model: (string) config('rag.embedding.model', 'nomic-embed-text'),
                    dimensions: (int) config('rag.embedding.dimensions', 768),
                    timeout: (int) config('rag.embedding.timeout', 30),
                    batchSize: (int) config('rag.embedding.batch_size', 100),
                ),
                default => new OpenAIEmbeddingProvider(
                    apiKey: (string) config('rag.embedding.api_key', (string) env('OPENAI_API_KEY', '')),
                    model: (string) config('rag.embedding.model', 'text-embedding-ada-002'),
                    dimensions: (int) config('rag.embedding.dimensions', 1536),
                    timeout: (int) config('rag.embedding.timeout', 30),
                    batchSize: (int) config('rag.embedding.batch_size', 100),
                ),
            };
        });

        $this->app->singleton(EmbeddingServiceInterface::class, fn ($app): EmbeddingService => new EmbeddingService(
            provider: $app->make(EmbeddingProviderInterface::class),
            cache: $app->make(CacheRepository::class),
            cacheTtl: (int) config('rag.embedding.cache_ttl', 86400),
        ));
    }

    public function boot(): void
    {
        if (! config('modules.modules.embedding.enabled', true)) {
            return;
        }
    }
}
