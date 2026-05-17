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
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

class ChatModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RAGPipelineServiceInterface::class, fn ($app): RAGPipelineService => new RAGPipelineService(
            embedder: $app->make(EmbeddingServiceInterface::class),
            vectorStore: $app->make(VectorStoreInterface::class),
            llm: $app->make(LLMServiceInterface::class),
            providerFactory: $app->make(ProviderFactory::class),
            cache: $app->make(CacheRepository::class),
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
