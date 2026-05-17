<?php

declare(strict_types=1);

namespace Modules\ChatModule\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Modules\ChatModule\Commands\CleanupExpiredSessions;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Chat Module Service Provider
 *
 * Registers the RAGPipelineService as a singleton bound to the
 * RAGPipelineServiceInterface, wiring all required dependencies
 * (embedder, vector store, LLM, cache, provider factory, term alias service)
 * and reading configuration defaults from config/rag.php.
 * In boot(), loads migrations and routes when the module is enabled,
 * and registers the chat:cleanup Artisan command in console mode.
 */
class ChatModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services
     *
     * Binds RAGPipelineServiceInterface to a RAGPipelineService singleton.
     * All constructor dependencies are resolved from the container; scalar
     * configuration values are read from config/rag.php with sensible defaults.
     * Active embedding and LLM model IDs are initialised as null (resolved
     * at runtime from the ai_models table by the service constructor).
     *
     *
     * @example Automatically called by Laravel, no manual invocation needed.
     */
    public function register(): void
    {
        $this->app->singleton(RAGPipelineServiceInterface::class, fn ($app): RAGPipelineService => new RAGPipelineService(
            embedder: $app->make(EmbeddingServiceInterface::class),
            vectorStore: $app->make(VectorStoreInterface::class),
            llm: $app->make(LLMServiceInterface::class),
            providerFactory: $app->make(ProviderFactory::class),
            cache: $app->make(CacheRepository::class),
            termAliasService: $app->make(TermAliasServiceInterface::class),
            topK: (int) config('rag.search.top_k', 5),
            similarityThreshold: (float) config('rag.search.similarity_threshold', 0.65),
            maxQuestionLength: (int) config('rag.chat.max_question_length', 1000),
            maxMessagesPerSession: (int) config('rag.chat.max_messages_per_session', 100),
            searchMode: (string) config('rag.search.mode', 'hybrid'),
            queryExpansionEnabled: (bool) config('rag.search.query_expansion.enabled', false),
            numExpansionQueries: (int) config('rag.search.query_expansion.num_queries', 3),
            mmrEnabled: (bool) config('rag.search.mmr.enabled', true),
            mmrLambda: (float) config('rag.search.mmr.lambda', 0.7),
            maxTokens: (int) config('rag.llm.max_tokens', 4096),
            activeEmbeddingModelId: null,
            activeLlmModelId: null,
        ));
    }

    /**
     * Bootstrap module services
     *
     * Loads database migrations and route files when the module is enabled
     * (config/modules.php). In console mode, registers the CleanupExpiredSessions
     * Artisan command so it is available via php artisan list.
     *
     *
     * @example Automatically called by Laravel after all providers are registered.
     */
    public function boot(): void
    {
        if (! config('modules.modules.chat.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/chat.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredSessions::class,
            ]);
        }
    }
}
