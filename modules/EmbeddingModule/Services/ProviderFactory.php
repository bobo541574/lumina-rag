<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Illuminate\Contracts\Cache\Repository;
use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Services\OllamaLLMProvider;
use Modules\LLMModule\Services\OpenAILLMProvider;
use Modules\SettingsModule\Models\AiModel;

class ProviderFactory
{
    public function createEmbeddingProvider(AiModel $model): EmbeddingProviderInterface
    {
        return match ($model->provider) {
            'ollama' => new OllamaEmbeddingProvider(
                baseUrl: $model->base_url ?? 'http://localhost:11434',
                model: $model->model,
                dimensions: $model->dimensions ?? 768,
                timeout: $model->timeout,
                batchSize: $model->batch_size ?? 100,
            ),
            default => new OpenAIEmbeddingProvider(
                apiKey: $model->api_key ?? (string) config('rag.embedding.api_key', ''),
                model: $model->model,
                dimensions: $model->dimensions ?? 1536,
                timeout: $model->timeout,
                batchSize: $model->batch_size ?? 100,
            ),
        };
    }

    public function createLLMProvider(AiModel $model): LLMProviderInterface
    {
        return match ($model->provider) {
            'ollama' => new OllamaLLMProvider(
                baseUrl: $model->base_url ?? 'http://localhost:11434',
                model: $model->model,
                timeout: $model->timeout,
            ),
            default => new OpenAILLMProvider(
                apiKey: $model->api_key ?? (string) config('rag.llm.api_key', ''),
                model: $model->model,
                timeout: $model->timeout,
            ),
        };
    }

    public function createEmbeddingService(AiModel $model): EmbeddingService
    {
        $provider = $this->createEmbeddingProvider($model);
        $cache = app(Repository::class);
        $cacheTtl = $model->cache_ttl ?? (int) config('rag.embedding.cache_ttl', 86400);

        return new EmbeddingService($provider, $cache, $cacheTtl);
    }
}
