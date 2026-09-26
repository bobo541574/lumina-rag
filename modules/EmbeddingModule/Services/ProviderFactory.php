<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Illuminate\Contracts\Cache\Repository;
use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Services\ClaudeLLMProvider;
use Modules\LLMModule\Services\DeepSeekLLMProvider;
use Modules\LLMModule\Services\GeminiLLMProvider;
use Modules\LLMModule\Services\OllamaLLMProvider;
use Modules\LLMModule\Services\OpenAILLMProvider;
use Modules\SettingsModule\Models\AiModel;

/**
 * Provider Factory
 *
 * Factory responsible for instantiating embedding and LLM provider instances
 * based on an AiModel configuration record. Supports both Ollama (local) and
 * OpenAI providers with per-model configuration including API keys, URLs,
 * dimensions, timeouts, and batch sizes.
 *
 * This is the central switchboard that maps persisted model configurations
 * to concrete provider and service instances. Each factory method reads the
 * model's `provider` field to determine which implementation to create.
 *
 * @param  AiModel  $model  Model config used to select and configure the provider. Example: AiModel::find("01J...")
 *
 * @throws \RuntimeException When an unsupported provider type is encountered
 */
class ProviderFactory
{
    /**
     * Create an embedding provider from an AiModel config.
     *
     * Inspects $model->provider and returns either an OllamaEmbeddingProvider
     * or an OpenAIEmbeddingProvider with parameters drawn from the model record.
     *
     * @param  AiModel  $model  Model configuration record. Example: AiModel::where('type', 'embedding')->first()
     * @return EmbeddingProviderInterface Configured embedding provider. Example: new OpenAIEmbeddingProvider(...)
     *
     * @throws \RuntimeException When the provider type is not supported
     */
    public function createEmbeddingProvider(AiModel $model): EmbeddingProviderInterface
    {
        return match ($model->provider) {
            'ollama' => new OllamaEmbeddingProvider(
                baseUrl: $model->baseUrl(),
                model: $model->modelName(),
                dimensions: $model->dimensions(),
                timeout: $model->timeout(),
                batchSize: $model->batchSize(),
            ),
            'gemini' => new GeminiEmbeddingProvider(
                apiKey: $model->apiKey(),
                model: $model->modelName(),
                dimensions: $model->dimensions(),
                timeout: $model->timeout(),
                batchSize: $model->batchSize(),
                baseUrl: $model->baseUrl(),
            ),
            default => new OpenAIEmbeddingProvider(
                apiKey: $model->apiKey(),
                model: $model->modelName(),
                dimensions: $model->dimensions(),
                timeout: $model->timeout(),
                batchSize: $model->batchSize(),
                baseUrl: $model->baseUrl(),
            ),
        };
    }

    /**
     * Create an LLM provider from an AiModel config.
     *
     * Inspects $model->provider and returns the matching provider with
     * parameters drawn from the model record; unknown providers are treated
     * as OpenAI-compatible (honouring the model's base_url).
     *
     * @param  AiModel  $model  Model configuration record. Example: AiModel::where('type', 'llm')->first()
     * @return LLMProviderInterface Configured LLM provider. Example: new OpenAILLMProvider(...)
     *
     * @throws \RuntimeException When the provider type is not supported
     */
    public function createLLMProvider(AiModel $model): LLMProviderInterface
    {
        return match ($model->provider) {
            'ollama' => new OllamaLLMProvider(
                baseUrl: $model->baseUrl(),
                model: $model->modelName(),
                timeout: $model->timeout(),
            ),
            'gemini' => new GeminiLLMProvider(
                apiKey: $model->apiKey(),
                model: $model->modelName(),
                timeout: $model->timeout(),
                baseUrl: $model->baseUrl(),
            ),
            'claude' => new ClaudeLLMProvider(
                apiKey: $model->apiKey(),
                model: $model->modelName(),
                timeout: $model->timeout(),
                baseUrl: $model->baseUrl(),
            ),
            'deepseek' => new DeepSeekLLMProvider(
                apiKey: $model->apiKey(),
                model: $model->modelName(),
                timeout: $model->timeout(),
                baseUrl: $model->baseUrl(),
            ),
            default => new OpenAILLMProvider(
                apiKey: $model->apiKey(),
                model: $model->modelName(),
                timeout: $model->timeout(),
                baseUrl: $model->baseUrl(),
            ),
        };
    }

    /**
     * Create a fully wired EmbeddingService for a given model.
     *
     * Convenience method that composes createEmbeddingProvider with a
     * fresh cache instance and the model's cache TTL into a ready-to-use
     * EmbeddingService.
     *
     * @param  AiModel  $model  Model configuration record. Example: AiModel::find("01J...")
     * @return EmbeddingService Ready-to-use service with caching. Example: $factory->createEmbeddingService($model)
     *
     * @throws \RuntimeException When provider creation fails
     */
    public function createEmbeddingService(AiModel $model): EmbeddingService
    {
        $provider = $this->createEmbeddingProvider($model);
        $cache = app(Repository::class);
        $cacheTtl = $model->cacheTtl();

        return new EmbeddingService($provider, $cache, $cacheTtl);
    }
}
