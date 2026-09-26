<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use App\Support\HttpJsonClient;
use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

/**
 * OpenAI-compatible Chat Completions provider.
 *
 * Talks to any endpoint implementing the OpenAI `/chat/completions` API
 * (OpenAI itself, DeepSeek, vLLM, Groq, Azure-style gateways) for both
 * synchronous and streaming text generation. The base URL and error label
 * are the only per-vendor differences.
 *
 * The non-streaming path retries transient failures via HttpJsonClient; the
 * streaming path parses SSE `data:` lines via StreamsHttpLines.
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class OpenAILLMProvider implements LLMProviderInterface
{
    use StreamsHttpLines;

    /** @var string API key for bearer authentication */
    private string $apiKey;

    /** @var string LLM model name (e.g. "gpt-4o") */
    private string $model;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /** @var string API base URL without trailing slash */
    private string $baseUrl;

    /** @var string Vendor name used in error messages */
    private string $label;

    /**
     * @param  string  $apiKey  API key. Example: "sk-proj-..."
     * @param  string  $model  Model identifier. Example: "gpt-4o"
     * @param  int  $timeout  Request timeout in seconds. Example: 60
     * @param  string  $baseUrl  API base URL. Example: "https://api.openai.com/v1"
     * @param  string  $label  Vendor name for error messages. Example: "OpenAI"
     */
    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        int $timeout = 60,
        string $baseUrl = 'https://api.openai.com/v1',
        string $label = 'OpenAI',
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->label = $label;
    }

    /**
     * Perform a synchronous completion via the Chat Completions API.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled prompt (context + question). Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3, "max_tokens" => 4096, "model" => "gpt-4o"]
     * @return LLMResponse Response with content and token usage. Example: new LLMResponse("The answer is...", 150, 50, 200, "gpt-4o", "stop")
     *
     * @throws \RuntimeException When the API returns no choices, HTTP 4xx, or repeated 5xx
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): LLMResponse
    {
        $response = HttpJsonClient::post(
            $this->baseUrl.'/chat/completions',
            $this->headers(),
            $this->buildPayload($systemPrompt, $userPrompt, $options),
            $this->timeout,
            $this->label,
        );

        $choice = $response['choices'][0] ?? throw new \RuntimeException("{$this->label} API returned no choices");

        return new LLMResponse(
            content: $choice['message']['content'] ?? '',
            promptTokens: $response['usage']['prompt_tokens'] ?? 0,
            completionTokens: $response['usage']['completion_tokens'] ?? 0,
            totalTokens: $response['usage']['total_tokens'] ?? 0,
            model: $response['model'] ?? $this->model,
            finishReason: $choice['finish_reason'] ?? null,
        );
    }

    /**
     * Perform a streaming completion, yielding content deltas as they arrive.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled prompt. Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3]
     * @return Generator Yields content strings. Example: foreach ($gen as $chunk) { echo $chunk; }
     *
     * @throws \RuntimeException When the server returns an error status or the transfer fails
     */
    public function completeStream(string $systemPrompt, string $userPrompt, array $options = []): Generator
    {
        yield from $this->streamLines(
            $this->baseUrl.'/chat/completions',
            [...$this->headers(), 'Content-Type: application/json'],
            $this->buildPayload($systemPrompt, $userPrompt, $options) + ['stream' => true],
            $this->timeout,
            $this->label,
            static fn (string $line): array => self::parseChatCompletionLine($line),
        );
    }

    /**
     * Return the configured model name identifier.
     *
     * @return string Model name. Example: "gpt-4o"
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Estimate token count for a text string.
     *
     * Uses a rough approximation (character length / 4) which is suitable
     * for context budget estimation without a full tokenizer.
     *
     * @param  string  $text  Input text. Example: "This is a sample sentence."
     * @return int Estimated token count. Example: 8
     */
    public function countTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Chat Completions request body shared by complete() and completeStream().
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled prompt. Example: "Question: What is X?"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3, "max_tokens" => 4096]
     * @return array Request payload. Example: ["model" => "gpt-4o", "messages" => [...], "temperature" => 0.3]
     */
    private function buildPayload(string $systemPrompt, string $userPrompt, array $options): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => (float) ($options['temperature'] ?? 0.3),
        ];

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        return $payload;
    }

    /**
     * @return array<string> Authentication headers. Example: ["Authorization: Bearer sk-..."]
     */
    private function headers(): array
    {
        return ['Authorization: Bearer '.$this->apiKey];
    }

    /**
     * Extract the text delta from one OpenAI-compatible SSE line.
     *
     * @param  string  $line  Trimmed SSE line. Example: 'data: {"choices":[{"delta":{"content":"Hi"}}]}'
     * @return array<string> Zero or one delta. Example: ["Hi"]
     *
     * @throws \JsonException On malformed JSON (skipped by streamLines)
     */
    private static function parseChatCompletionLine(string $line): array
    {
        if ($line === 'data: [DONE]' || ! str_starts_with($line, 'data: ')) {
            return [];
        }

        $parsed = json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);

        return [(string) ($parsed['choices'][0]['delta']['content'] ?? '')];
    }
}
