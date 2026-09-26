<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use App\Support\HttpJsonClient;
use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

/**
 * Anthropic Claude LLM provider using raw cURL.
 *
 * Communicates with the Anthropic Messages API (https://api.anthropic.com/v1/messages)
 * for both synchronous and streaming text generation. Supports configurable models,
 * temperature, and max_tokens.
 *
 * Uses the native Anthropic API format with system prompt as a top-level parameter
 * and messages as an array of user/assistant turns. Streaming uses SSE with
 * content_block_delta events.
 *
 * @param  string  $apiKey  Anthropic API key. Example: "sk-ant-..."
 * @param  string  $model  Claude model identifier. Example: "claude-sonnet-4-5-20250929"
 * @param  int  $timeout  cURL request timeout in seconds. Example: 60
 * @param  string  $baseUrl  API base URL. Example: "https://api.anthropic.com/v1"
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class ClaudeLLMProvider implements LLMProviderInterface
{
    use StreamsHttpLines;

    /** @var string Anthropic API key */
    private string $apiKey;

    /** @var string Claude model name (e.g. "claude-sonnet-4-5-20250929") */
    private string $model;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /** @var string API base URL */
    private string $baseUrl;

    /** @var string Anthropic API version header */
    private string $apiVersion;

    /**
     * @param  string  $apiKey  Anthropic API key. Example: "sk-ant-..."
     * @param  string  $model  Model identifier. Example: "claude-sonnet-4-5-20250929"
     * @param  int  $timeout  Request timeout in seconds. Example: 60
     * @param  string  $baseUrl  API base URL. Example: "https://api.anthropic.com/v1"
     * @param  string  $apiVersion  API version. Example: "2023-06-01"
     */
    public function __construct(
        string $apiKey,
        string $model = 'claude-sonnet-4-5-20250929',
        int $timeout = 60,
        string $baseUrl = 'https://api.anthropic.com/v1',
        string $apiVersion = '2023-06-01',
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiVersion = $apiVersion;
    }

    /**
     * Perform a synchronous LLM completion via the Anthropic Messages API.
     *
     * Sends system prompt as a top-level parameter and user prompt as a message.
     * Returns an LLMResponse with content and token usage metadata.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled user prompt. Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3, "max_tokens" => 4096, "model" => "claude-sonnet-4-5-20250929"]
     * @return LLMResponse Response with content and token usage. Example: new LLMResponse("The answer is...", 150, 50, 200, "claude-sonnet-4-5-20250929", "stop")
     *
     * @throws \RuntimeException When the API returns no content, HTTP 4xx, or repeated 5xx
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): LLMResponse
    {
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $maxTokens = (int) ($options['max_tokens'] ?? 4096);

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $response = $this->sendRequest($payload);

        $content = '';
        if (isset($response['content'])) {
            foreach ($response['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= $block['text'] ?? '';
                }
            }
        }

        $usage = $response['usage'] ?? [];

        return new LLMResponse(
            content: $content,
            promptTokens: $usage['input_tokens'] ?? 0,
            completionTokens: $usage['output_tokens'] ?? 0,
            totalTokens: ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            model: $response['model'] ?? $this->model,
            finishReason: $response['stop_reason'] ?? null,
        );
    }

    /**
     * Perform a streaming LLM completion via the Anthropic Messages API.
     *
     * Uses StreamsHttpLines to stream SSE events. Handles the Anthropic
     * streaming format with event types: message_start, content_block_delta,
     * content_block_stop, message_delta, message_stop.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled user prompt. Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3]
     * @return Generator Yields content strings. Example: foreach ($gen as $chunk) { echo $chunk; }
     *
     * @throws \RuntimeException When the server returns an error status or the transfer fails
     */
    public function completeStream(string $systemPrompt, string $userPrompt, array $options = []): Generator
    {
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $maxTokens = (int) ($options['max_tokens'] ?? 4096);

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => true,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $url = $this->baseUrl.'/messages';

        $currentEvent = '';

        yield from $this->streamLines(
            $url,
            [
                'x-api-key: '.$this->apiKey,
                'anthropic-version: '.$this->apiVersion,
                'Content-Type: application/json',
            ],
            $payload,
            $this->timeout,
            'Claude',
            function (string $line) use (&$currentEvent): array {
                if (str_starts_with($line, 'event: ')) {
                    $currentEvent = substr($line, 7);

                    return [];
                }
                if ($currentEvent !== 'content_block_delta' || ! str_starts_with($line, 'data: ')) {
                    return [];
                }

                $parsed = json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);

                return isset($parsed['delta']['text']) ? [$parsed['delta']['text']] : [];
            },
        );
    }

    /**
     * Return the configured model name identifier.
     *
     * @return string Model name. Example: "claude-sonnet-4-5-20250929"
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
     * Execute a single HTTP request to the Anthropic Messages API.
     *
     * Retries transient failures via HttpJsonClient; 4xx errors throw immediately.
     *
     * @param  array  $payload  Request payload. Example: ["model" => "claude-sonnet-4-5-20250929", "messages" => [...], "max_tokens" => 4096]
     * @return array Decoded response array. Example: ["content" => [["type" => "text", "text" => "...", "stop_reason" => "end_turn"]], "usage" => [...]]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed JSON
     */
    private function sendRequest(array $payload): array
    {
        return HttpJsonClient::post(
            $this->baseUrl.'/messages',
            ['x-api-key: '.$this->apiKey, 'anthropic-version: '.$this->apiVersion],
            $payload,
            $this->timeout,
            'Claude',
        );
    }
}
