<?php

declare(strict_types=1);

use Modules\LLMModule\Services\OllamaLLMProvider;
use Modules\LLMModule\Services\OpenAILLMProvider;

test('ollama_provider_returns_model_name', function (): void {
    $provider = new OllamaLLMProvider('http://localhost:11434', 'qwen3.5:9b');
    expect($provider->getModelName())->toBe('qwen3.5:9b');
});

test('openai_provider_returns_model_name', function (): void {
    $provider = new OpenAILLMProvider('sk-test', 'gpt-4o');
    expect($provider->getModelName())->toBe('gpt-4o');
});

test('count_tokens_approximates_correctly', function (): void {
    $provider = new OpenAILLMProvider('sk-test', 'gpt-4o');
    $tokens = $provider->countTokens('Hello world');

    expect($tokens)->toBeGreaterThan(0);
    expect($tokens)->toBe(3);
});
