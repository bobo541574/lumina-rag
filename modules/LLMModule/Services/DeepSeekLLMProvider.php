<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

/**
 * DeepSeek LLM provider.
 *
 * DeepSeek speaks the OpenAI Chat Completions format, so this is the
 * OpenAI-compatible provider with DeepSeek's defaults.
 */
class DeepSeekLLMProvider extends OpenAILLMProvider
{
    /**
     * @param  string  $apiKey  DeepSeek API key. Example: "sk-..."
     * @param  string  $model  Model identifier. Example: "deepseek-chat"
     * @param  int  $timeout  Request timeout in seconds. Example: 60
     * @param  string  $baseUrl  API base URL. Example: "https://api.deepseek.com/v1"
     */
    public function __construct(
        string $apiKey,
        string $model = 'deepseek-chat',
        int $timeout = 60,
        string $baseUrl = 'https://api.deepseek.com/v1',
    ) {
        parent::__construct($apiKey, $model, $timeout, $baseUrl, 'DeepSeek');
    }
}
