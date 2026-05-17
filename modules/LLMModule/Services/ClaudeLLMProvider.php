<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

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
     * Uses cURL multi-handle to stream SSE events. Handles the Anthropic
     * streaming format with event types: message_start, content_block_delta,
     * content_block_stop, message_delta, message_stop.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled user prompt. Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3]
     * @return Generator Yields content strings. Example: foreach ($gen as $chunk) { echo $chunk; }
     *
     * @throws \RuntimeException When cURL multi-handle encounters a fatal error
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

        $queue = [];
        $lineBuffer = '';
        $currentEvent = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: '.$this->apiKey,
                'anthropic-version: '.$this->apiVersion,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$queue, &$lineBuffer, &$currentEvent): int {
                $lineBuffer .= $data;
                $lines = explode("\n", $lineBuffer);
                $lineBuffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    if (str_starts_with($line, 'event: ')) {
                        $currentEvent = substr($line, 7);

                        continue;
                    }

                    if (str_starts_with($line, 'data: ')) {
                        if ($currentEvent !== 'content_block_delta') {
                            continue;
                        }

                        $json = substr($line, 6);
                        try {
                            $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                            if (isset($parsed['delta']['text'])) {
                                $queue[] = $parsed['delta']['text'];
                            }
                        } catch (\JsonException) {
                            // skip malformed JSON
                        }
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
     * Implements retry with exponential backoff (1s, 5s, 25s) for
     * transient server errors. Client errors (4xx) throw immediately.
     *
     * @param  array  $payload  Request payload. Example: ["model" => "claude-sonnet-4-5-20250929", "messages" => [...], "max_tokens" => 4096]
     * @return array Decoded response array. Example: ["content" => [["type" => "text", "text" => "...", "stop_reason" => "end_turn"]], "usage" => [...]]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed JSON
     */
    private function sendRequest(array $payload): array
    {
        $maxAttempts = 3;
        $backoff = [1_000_000, 5_000_000, 25_000_000];

        $url = $this->baseUrl.'/messages';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: '.$this->apiKey,
                    'anthropic-version: '.$this->apiVersion,
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
                throw new \RuntimeException("Claude API returned HTTP {$httpCode}: {$response}");
            }

            if ($attempt < $maxAttempts) {
                usleep($backoff[$attempt - 1]);
            }
        }

        if ($error !== '') {
            throw new \RuntimeException("Claude API request failed after {$maxAttempts} attempts: {$error}");
        }

        throw new \RuntimeException("Claude API returned HTTP {$httpCode} after {$maxAttempts} attempts: {$response}");
    }
}
