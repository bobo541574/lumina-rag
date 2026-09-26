<?php

declare(strict_types=1);

namespace Modules\LLMModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\LLMModule\Services\LLMService;
use Modules\SettingsModule\Models\AiModel;

/**
 * LLM Module Service Provider
 *
 * Registers the LLM Module's core services into the Laravel container.
 * Binds the LLMProviderInterface to a fallback provider built by
 * ProviderFactory from `AiModel::fromConfig('llm')` (config/rag.php), and binds
 * the LLMServiceInterface to the LLMService that wraps it with prompt assembly.
 * RAGPipelineService replaces this fallback with the active AiModel's provider.
 */
class LLMModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services in the container.
     *
     * Creates two singletons:
     * 1. LLMProviderInterface — fallback provider built from config/rag.php via ProviderFactory
     * 2. LLMServiceInterface — prompt-assembly service wrapping the provider
     */
    public function register(): void
    {
        // Fallback provider when no LLM AiModel is configured; built through
        // ProviderFactory so config-based and DB-based models share one code path.
        $this->app->singleton(LLMProviderInterface::class, fn ($app): LLMProviderInterface => $app
            ->make(ProviderFactory::class)
            ->createLLMProvider(AiModel::fromConfig('llm')));

        $this->app->singleton(LLMServiceInterface::class, fn ($app): LLMService => new LLMService(
            provider: $app->make(LLMProviderInterface::class),
            maxContextTokens: AiModel::fromConfig('llm')->maxContextTokens(),
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
        if (! config('modules.modules.llm.enabled', true)) {
            return;
        }
    }
}
