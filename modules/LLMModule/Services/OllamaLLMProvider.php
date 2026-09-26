<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use App\Support\HttpJsonClient;
use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

/**
 * Ollama LLM provider using raw cURL.
 *
 * Communicates with a local Ollama instance's /api/chat endpoint for both
 * synchronous and streaming text generation. Supports configurable models,
 * temperature, and max_tokens (mapped to num_predict for Ollama).
 *
 * Streaming parses newline-delimited JSON via StreamsHttpLines; the
 * non-streaming path retries transient failures via HttpJsonClient.
 *
 * Requires a running Ollama server (default http://localhost:11434) with
 * the configured model pulled. No authentication is needed for local instances.
 *
 * @param  string  $baseUrl  Ollama server base URL. Example: "http://localhost:11434"
 * @param  string  $model  LLM model name. Example: "llama3.2"
 * @param  int  $timeout  cURL request timeout in seconds. Example: 60
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class OllamaLLMProvider implements LLMProviderInterface
{
    use StreamsHttpLines;

    /** @var string Ollama server base URL (trailing slash stripped) */
    private string $baseUrl;

    /** @var string LLM model name (e.g. "llama3.2") */
    private string $model;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /**
     * @param  string  $baseUrl  Ollama server base URL. Example: "http://localhost:11434"
     * @param  string  $model  Model identifier. Example: "llama3.2"
     * @param  int  $timeout  Request timeout in seconds. Example: 60
     */
    public function __construct(
        string $baseUrl = 'http://localhost:11434',
        string $model = 'llama3.2',
        int $timeout = 300,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Perform a synchronous LLM completion via the Ollama Chat API.
     *
     * Sends system and user messages as a chat payload, reads back the
     * response including message content, token counts (prompt_eval_count,
     * eval_count), and done_reason.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled prompt. Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3, "max_tokens" => 4096, "model" => "llama3.2"]
     * @return LLMResponse Response with content and token usage. Example: new LLMResponse("The answer is...", 150, 50, 200, "llama3.2", "stop")
     *
     * @throws \RuntimeException When the API returns no message, HTTP 4xx, or repeated 5xx
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): LLMResponse
    {
        $temperature = (float) ($options['temperature'] ?? 0.3);

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'stream' => false,
            'options' => [
                'temperature' => $temperature,
            ],
        ];

        if (isset($options['max_tokens'])) {
            $payload['options']['num_predict'] = $options['max_tokens'];
        }

        if (isset($options['num_ctx'])) {
            $payload['options']['num_ctx'] = (int) $options['num_ctx'];
        }

        if (isset($options['think'])) {
            $payload['think'] = (bool) $options['think'];
        } elseif ($this->isQwenModel()) {
            $payload['think'] = false;
        }

        $response = $this->sendRequest($payload);

        $message = $response['message'] ?? throw new \RuntimeException('Ollama API returned no message');

        return new LLMResponse(
            content: $message['content'] ?? '',
            promptTokens: $response['prompt_eval_count'] ?? 0,
            completionTokens: $response['eval_count'] ?? 0,
            totalTokens: ($response['prompt_eval_count'] ?? 0) + ($response['eval_count'] ?? 0),
            model: $response['model'] ?? $this->model,
            finishReason: $response['done_reason'] ?? null,
        );
    }

    /**
     * Perform a streaming LLM completion via the Ollama Chat API.
     *
     * Uses StreamsHttpLines to stream newline-delimited JSON from the
     * Ollama API. Yields content deltas (message.content) as they arrive.
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
        $temperature = (float) ($options['temperature'] ?? 0.3);

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'stream' => true,
            'options' => [
                'temperature' => $temperature,
            ],
        ];

        if (isset($options['max_tokens'])) {
            $payload['options']['num_predict'] = $options['max_tokens'];
        }

        if (isset($options['num_ctx'])) {
            $payload['options']['num_ctx'] = (int) $options['num_ctx'];
        }

        if (isset($options['think'])) {
            $payload['think'] = (bool) $options['think'];
        } elseif ($this->isQwenModel()) {
            $payload['think'] = false;
        }

        $url = $this->baseUrl.'/api/chat';

        yield from $this->streamLines(
            $url,
            ['Content-Type: application/json'],
            $payload,
            $this->timeout,
            'Ollama',
            static function (string $line): array {
                $parsed = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                return [(string) ($parsed['message']['content'] ?? '')];
            },
        );
    }

    /**
     * Return the configured model name identifier.
     *
     * @return string Model name. Example: "llama3.2"
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
     * Check whether the loaded model is a Qwen variant
     *
     * Qwen models (qwen3.5, qwen2.5, sorc/qwen*, etc.) ship with chain-of-thought
     * reasoning enabled by default. This detection is used to automatically set
     * think=false in the Ollama payload unless explicitly overridden via options.
     *
     * @return bool True if the model name contains "qwen" (case-insensitive).
     *              Example: true for "qwen3.5:9b" or "sorc/qwen3.5-claude-4.6-opus:9b"
     */
    private function isQwenModel(): bool
    {
        return stripos($this->model, 'qwen') !== false;
    }

    /**
     * Execute a single HTTP request to the Ollama /api/chat endpoint.
     *
     * Retries transient failures via HttpJsonClient; 4xx errors throw immediately.
     *
     * @param  array  $payload  Request payload. Example: ["model" => "llama3.2", "messages" => [...], "stream" => false]
     * @return array Decoded response array. Example: ["model" => "llama3.2", "message" => ["content" => "...", "role" => "assistant"], "done_reason" => "stop"]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed JSON
     */
    private function sendRequest(array $payload): array
    {
        return HttpJsonClient::post($this->baseUrl.'/api/chat', [], $payload, $this->timeout, 'Ollama');
    }
}
