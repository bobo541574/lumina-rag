<?php

declare(strict_types=1);

namespace Modules\LLMModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\LLMModule\Services\LLMService;
use Modules\LLMModule\Services\OllamaLLMProvider;
use Modules\LLMModule\Services\OpenAILLMProvider;

/**
 * LLM Module Service Provider
 *
 * Registers the LLM Module's core services into the Laravel container.
 * Binds the LLMProviderInterface to a concrete provider (OpenAI or Ollama)
 * based on config/rag.php settings, and binds the LLMServiceInterface to
 * the LLMService that wraps the provider with prompt assembly logic.
 *
 * The provider is selected by the `rag.llm.provider` config value, defaulting
 * to OpenAI. Ollama requires a local running instance at the configured base_url.
 *
 * @throws \RuntimeException If the configured provider type is not supported
 */
class LLMModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module services in the container.
     *
     * Creates two singletons:
     * 1. LLMProviderInterface — concrete provider based on config
     * 2. LLMServiceInterface — prompt-assembly service wrapping the provider
     */
    public function register(): void
    {
        $this->app->singleton(LLMProviderInterface::class, function (): LLMProviderInterface {
            $provider = (string) config('rag.llm.provider', 'openai');

            return match ($provider) {
                'ollama' => new OllamaLLMProvider(
                    baseUrl: (string) config('rag.llm.base_url', 'http://localhost:11434'),
                    model: (string) config('rag.llm.model', 'llama3.2'),
                    timeout: (int) config('rag.llm.timeout', 60),
                ),
                default => new OpenAILLMProvider(
                    apiKey: (string) config('rag.llm.api_key', (string) env('OPENAI_API_KEY', '')),
                    model: (string) config('rag.llm.model', 'gpt-4o'),
                    timeout: (int) config('rag.llm.timeout', 60),
                ),
            };
        });

        $this->app->singleton(LLMServiceInterface::class, fn ($app): LLMService => new LLMService(
            provider: $app->make(LLMProviderInterface::class),
            maxContextTokens: (int) config('rag.llm.max_context_tokens', 4000),
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
