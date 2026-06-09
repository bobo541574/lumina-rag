<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

/**
 * Ollama LLM provider using raw cURL.
 *
 * Communicates with a local Ollama instance's /api/chat endpoint for both
 * synchronous and streaming text generation. Supports configurable models,
 * temperature, and max_tokens (mapped to num_predict for Ollama).
 *
 * Streaming is implemented via cURL multi-handle with a WRITEFUNCTION
 * callback that parses newline-delimited JSON and yields content deltas.
 * The non-streaming path uses exponential-backoff retry (3 attempts) for
 * transient server errors.
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
     * Uses cURL multi-handle to stream newline-delimited JSON from the
     * Ollama API. Yields content deltas (message.content) as they arrive.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled prompt. Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3]
     * @return Generator Yields content strings. Example: foreach ($gen as $chunk) { echo $chunk; }
     *
     * @throws \RuntimeException When cURL multi-handle encounters a fatal error
     */
    public function completeStream(string $systemPrompt, string $userPrompt, array $options = []): Generator
    {
        $temperature = (float) ($options['temperature'] ?? 0.3);

        $payload = [
            'model' => $this->model,
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

        if (isset($options['think'])) {
            $payload['think'] = (bool) $options['think'];
        } elseif ($this->isQwenModel()) {
            $payload['think'] = false;
        }

        $url = $this->baseUrl.'/api/chat';

        $queue = [];
        $lineBuffer = '';
        $errorBody = '';
        $httpChecked = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$queue, &$lineBuffer, &$errorBody, &$httpChecked): int {
                if (! $httpChecked) {
                    $httpChecked = true;
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($httpCode !== 200) {
                        $errorBody .= $data;

                        return strlen($data);
                    }
                }

                if ($errorBody !== '') {
                    $errorBody .= $data;

                    return strlen($data);
                }

                $lineBuffer .= $data;
                $lines = explode("\n", $lineBuffer);
                $lineBuffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    try {
                        $parsed = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                        $delta = $parsed['message']['content'] ?? '';
                        if ($delta !== '') {
                            $queue[] = $delta;
                        }
                    } catch (\JsonException) {
                        // skip malformed JSON
                    }
                }

                return strlen($data);
            },
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) {
                break;
            }

            while ($queue !== []) {
                yield array_shift($queue);
            }

            if ($running > 0 && $queue === []) {
                usleep(5_000);
            }
        } while ($running > 0);

        while ($queue !== []) {
            yield array_shift($queue);
        }

        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);

        if ($errorBody !== '') {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            throw new \RuntimeException("Ollama streaming request failed (HTTP {$httpCode}): {$errorBody}");
        }
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
     * Implements retry with exponential backoff (1s, 5s, 25s) for
     * transient server errors. Client errors (4xx) throw immediately.
     *
     * @param  array  $payload  Request payload. Example: ["model" => "llama3.2", "messages" => [...], "stream" => false]
     * @return array Decoded response array. Example: ["model" => "llama3.2", "message" => ["content" => "...", "role" => "assistant"], "done_reason" => "stop"]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed JSON
     */
    private function sendRequest(array $payload): array
    {
        $maxAttempts = 3;
        $backoff = [1_000_000, 5_000_000, 25_000_000];

        $url = $this->baseUrl.'/api/chat';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error === '' && $httpCode === 200) {
                return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            }

            if ($httpCode >= 400 && $httpCode < 500) {
                throw new \RuntimeException("Ollama LLM returned HTTP {$httpCode}: {$response}");
            }

            if ($attempt < $maxAttempts) {
                usleep($backoff[$attempt - 1]);
            }
        }

        if ($error !== '') {
            throw new \RuntimeException("Ollama LLM request failed after {$maxAttempts} attempts: {$error}");
        }

        throw new \RuntimeException("Ollama LLM returned HTTP {$httpCode} after {$maxAttempts} attempts: {$response}");
    }
}
