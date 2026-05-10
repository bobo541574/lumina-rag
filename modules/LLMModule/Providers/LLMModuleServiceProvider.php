<?php

declare(strict_types=1);

namespace Modules\LLMModule\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\LLMModule\Contracts\LLMProviderInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\LLMModule\Services\LLMService;
use Modules\LLMModule\Services\OllamaLLMProvider;
use Modules\LLMModule\Services\OpenAILLMProvider;

class LLMModuleServiceProvider extends ServiceProvider
{
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

    public function boot(): void
    {
        //
    }
}
