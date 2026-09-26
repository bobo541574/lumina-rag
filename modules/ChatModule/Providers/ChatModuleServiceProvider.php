<?php

declare(strict_types=1);

namespace Modules\ChatModule\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\ChatModule\Commands\CleanupExpiredSessions;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Events\KnowledgeChanged;
use Modules\ChatModule\Listeners\InvalidateSemanticCache;
use Modules\ChatModule\Services\Pipeline\ChunkProcessor;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;
use Modules\ChatModule\Services\Pipeline\FtsQueryBuilder;
use Modules\ChatModule\Services\Pipeline\PipelineSettings;
use Modules\ChatModule\Services\Pipeline\QueryRewriterService;
use Modules\ChatModule\Services\Pipeline\ResponseBuilder;
use Modules\ChatModule\Services\Pipeline\SessionManager;
use Modules\ChatModule\Services\QuestionClassifier;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\ChatModule\Services\RerankerService;
use Modules\ChatModule\Services\SemanticCacheService;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\SettingsModule\Contracts\TermAliasServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Chat Module Service Provider
 *
 * Registers the RAGPipelineService (scoped) bound to the
 * RAGPipelineServiceInterface, plus the QueryRewriterService and
 * RerankerService singletons, reading defaults from config/rag.php.
 * In boot(), loads migrations and routes when the module is enabled,
 * and registers the chat:cleanup Artisan command in console mode.
 */
class ChatModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services
     *
     * Binds RAGPipelineServiceInterface to a scoped RAGPipelineService (one
     * per request/job, so active AiModel changes are picked up by workers).
     * Settings come from PipelineSettings::fromConfig().
     *
     * @example Automatically called by Laravel, no manual invocation needed.
     */
    public function register(): void
    {
        $this->app->singleton(SemanticCacheService::class, fn ($app): SemanticCacheService => new SemanticCacheService);

        $this->app->singleton(QueryRewriterService::class, fn ($app): QueryRewriterService => new QueryRewriterService(
            filterExtractor: $app->make(FilterExtractor::class),
            termAliasService: $app->make(TermAliasServiceInterface::class),
            complexityThreshold: (int) config('rag.search.query_rewriter.complexity_threshold', 5),
        ));

        $this->app->singleton(RerankerService::class, fn ($app): RerankerService => new RerankerService(
            enabled: (bool) config('rag.reranker.enabled', false),
            baseUrl: (string) config('rag.reranker.base_url', 'http://localhost:11434'),
            model: (string) config('rag.reranker.model', 'bge-reranker:latest'),
            candidateK: (int) config('rag.reranker.candidate_k', 8),
            finalK: (int) config('rag.reranker.final_k', 5),
            timeout: (float) config('rag.reranker.timeout', 30),
        ));

        // Scoped, not singleton: the pipeline reads the active AiModels when
        // built, so long-running workers must pick up settings changes.
        $this->app->scoped(RAGPipelineServiceInterface::class, fn ($app): RAGPipelineService => new RAGPipelineService(
            embedder: $app->make(EmbeddingServiceInterface::class),
            vectorStore: $app->make(VectorStoreInterface::class),
            llm: $app->make(LLMServiceInterface::class),
            providerFactory: $app->make(ProviderFactory::class),
            termAliasService: $app->make(TermAliasServiceInterface::class),
            filterExtractor: $app->make(FilterExtractor::class),
            ftsQueryBuilder: $app->make(FtsQueryBuilder::class),
            queryRewriter: $app->make(QueryRewriterService::class),
            chunkProcessor: $app->make(ChunkProcessor::class),
            responseBuilder: $app->make(ResponseBuilder::class),
            sessionManager: $app->make(SessionManager::class),
            semanticCache: $app->make(SemanticCacheService::class),
            questionClassifier: $app->make(QuestionClassifier::class),
            reranker: $app->make(RerankerService::class),
            baseSettings: PipelineSettings::fromConfig(),
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

        Event::listen(KnowledgeChanged::class, InvalidateSemanticCache::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredSessions::class,
            ]);
        }
    }
}
