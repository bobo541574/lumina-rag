<?php

declare(strict_types=1);

use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\LLMModule\Services\ClaudeLLMProvider;
use Modules\LLMModule\Services\DeepSeekLLMProvider;
use Modules\LLMModule\Services\GeminiLLMProvider;
use Modules\LLMModule\Services\OllamaLLMProvider;
use Modules\LLMModule\Services\OpenAILLMProvider;
use Modules\SettingsModule\Models\AiModel;

test('ollama_provider_returns_model_name', function (): void {
    $provider = new OllamaLLMProvider('http://localhost:11434', 'qwen3.5:9b');
    expect($provider->getModelName())->toBe('qwen3.5:9b');
});

test('openai_provider_returns_model_name', function (): void {
    $provider = new OpenAILLMProvider('sk-test', 'gpt-4o');
    expect($provider->getModelName())->toBe('gpt-4o');
});

test('gemini_provider_returns_model_name', function (): void {
    $provider = new GeminiLLMProvider('ai-test-key', 'gemini-2.5-flash');
    expect($provider->getModelName())->toBe('gemini-2.5-flash');
});

test('claude_provider_returns_model_name', function (): void {
    $provider = new ClaudeLLMProvider('sk-ant-test', 'claude-sonnet-4-5-20250929');
    expect($provider->getModelName())->toBe('claude-sonnet-4-5-20250929');
});

test('deepseek_provider_returns_model_name', function (): void {
    $provider = new DeepSeekLLMProvider('sk-test', 'deepseek-chat');
    expect($provider->getModelName())->toBe('deepseek-chat');
});

test('count_tokens_approximates_correctly', function (): void {
    $provider = new OpenAILLMProvider('sk-test', 'gpt-4o');
    $tokens = $provider->countTokens('Hello world');

    expect($tokens)->toBeGreaterThan(0);
    expect($tokens)->toBe(3);
});

test('all_providers_count_tokens_consistently', function (): void {
    $text = 'This is a moderately long sentence to test token counting.';
    $providers = [
        new OpenAILLMProvider('sk-test', 'gpt-4o'),
        new GeminiLLMProvider('ai-test', 'gemini-2.5-flash'),
        new ClaudeLLMProvider('sk-ant-test', 'claude-sonnet-4-5-20250929'),
        new DeepSeekLLMProvider('sk-test', 'deepseek-chat'),
    ];

    foreach ($providers as $provider) {
        $tokens = $provider->countTokens($text);
        expect($tokens)->toBeGreaterThan(0);
        expect($tokens)->toBe((int) ceil(mb_strlen($text) / 4));
    }
});

test('new_providers_complete_stream_returns_generator', function (): void {
    $providers = [
        new GeminiLLMProvider('ai-test', 'gemini-2.5-flash'),
        new ClaudeLLMProvider('sk-ant-test', 'claude-sonnet-4-5-20250929'),
        new DeepSeekLLMProvider('sk-test', 'deepseek-chat'),
    ];

    foreach ($providers as $provider) {
        $gen = $provider->completeStream('system', 'user');
        expect($gen)->toBeInstanceOf(Generator::class);
    }
});

test('provider_factory_creates_ollama_llm_provider', function (): void {
    $model = new AiModel(['provider' => 'ollama', 'model' => 'llama3.2', 'base_url' => 'http://localhost:11434', 'timeout' => 60]);
    $factory = new ProviderFactory;
    $provider = $factory->createLLMProvider($model);

    expect($provider)->toBeInstanceOf(OllamaLLMProvider::class);
    expect($provider->getModelName())->toBe('llama3.2');
});

test('provider_factory_creates_gemini_llm_provider', function (): void {
    $model = new AiModel(['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'api_key' => 'ai-test-key', 'timeout' => 60]);
    $factory = new ProviderFactory;
    $provider = $factory->createLLMProvider($model);

    expect($provider)->toBeInstanceOf(GeminiLLMProvider::class);
    expect($provider->getModelName())->toBe('gemini-2.5-flash');
});

test('provider_factory_creates_claude_llm_provider', function (): void {
    $model = new AiModel(['provider' => 'claude', 'model' => 'claude-sonnet-4-5-20250929', 'api_key' => 'sk-ant-test', 'timeout' => 60]);
    $factory = new ProviderFactory;
    $provider = $factory->createLLMProvider($model);

    expect($provider)->toBeInstanceOf(ClaudeLLMProvider::class);
    expect($provider->getModelName())->toBe('claude-sonnet-4-5-20250929');
});

test('provider_factory_creates_deepseek_llm_provider', function (): void {
    $model = new AiModel(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'api_key' => 'sk-test', 'timeout' => 60]);
    $factory = new ProviderFactory;
    $provider = $factory->createLLMProvider($model);

    expect($provider)->toBeInstanceOf(DeepSeekLLMProvider::class);
    expect($provider->getModelName())->toBe('deepseek-chat');
});

test('provider_factory_defaults_to_openai_for_unknown_provider', function (): void {
    $model = new AiModel(['provider' => 'unknown', 'model' => 'gpt-4o', 'api_key' => 'sk-test', 'timeout' => 60]);
    $factory = new ProviderFactory;
    $provider = $factory->createLLMProvider($model);

    expect($provider)->toBeInstanceOf(OpenAILLMProvider::class);
    expect($provider->getModelName())->toBe('gpt-4o');
});
