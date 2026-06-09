<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

/**
 * OpenAI LLM provider using raw cURL.
 *
 * Communicates with the OpenAI Chat Completions API
 * (https://api.openai.com/v1/chat/completions) for both synchronous and
 * streaming text generation. Supports configurable models, temperature,
 * and max_tokens.
 *
 * Streaming is implemented via cURL multi-handle with a WRITEFUNCTION
 * callback that parses SSE data: lines and yields content deltas as they
 * arrive. The non-streaming path uses exponential-backoff retry (3 attempts)
 * for transient server errors.
 *
 * @param  string  $apiKey  OpenAI API key for bearer authentication. Example: "sk-proj-..."
 * @param  string  $model  LLM model identifier. Example: "gpt-4o"
 * @param  int  $timeout  cURL request timeout in seconds. Example: 60
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class OpenAILLMProvider implements LLMProviderInterface
{
    /** @var string OpenAI API key for bearer authentication */
    private string $apiKey;

    /** @var string LLM model name (e.g. "gpt-4o") */
    private string $model;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /**
     * @param  string  $apiKey  OpenAI API key. Example: "sk-proj-..."
     * @param  string  $model  Model identifier. Example: "gpt-4o"
     * @param  int  $timeout  Request timeout in seconds. Example: 60
     */
    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        int $timeout = 60,
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Perform a synchronous LLM completion via the OpenAI Chat API.
     *
     * Sends system prompt and user prompt as messages, returns a structured
     * LLMResponse with content and token usage metadata.
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
        $temperature = (float) ($options['temperature'] ?? 0.3);

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $temperature,
        ];

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        $response = $this->sendRequest($payload);

        $choice = $response['choices'][0] ?? throw new \RuntimeException('LLM API returned no choices');

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
     * Perform a streaming LLM completion.
     *
     * Uses cURL multi-handle to stream SSE events from the OpenAI API.
     * Yields content deltas as parsed JSON chunks from the response stream.
     * Final [DONE] signal is consumed without yielding.
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
            'model' => $options['model'] ?? $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $temperature,
            'stream' => true,
        ];

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        $url = 'https://api.openai.com/v1/chat/completions';

        $queue = [];
        $lineBuffer = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$queue, &$lineBuffer): int {
                $lineBuffer .= $data;
                $lines = explode("\n", $lineBuffer);
                $lineBuffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line === 'data: [DONE]') {
                        continue;
                    }
                    if (str_starts_with($line, 'data: ')) {
                        $json = substr($line, 6);
                        try {
                            $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                            $delta = $parsed['choices'][0]['delta']['content'] ?? '';
                            if ($delta !== '') {
                                $queue[] = $delta;
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
                usleep(5_000); // 5ms
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
     * Execute a single HTTP request to the OpenAI Chat Completions API.
     *
     * Implements retry with exponential backoff (1s, 5s, 25s) for
     * transient server errors. Client errors (4xx) throw immediately.
     *
     * @param  array  $payload  Request payload. Example: ["model" => "gpt-4o", "messages" => [...], "temperature" => 0.3]
     * @return array Decoded response array. Example: ["choices" => [...], "usage" => [...]]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed JSON
     */
    private function sendRequest(array $payload): array
    {
        $maxAttempts = 3;
        $backoff = [1_000_000, 5_000_000, 25_000_000];

        $url = 'https://api.openai.com/v1/chat/completions';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$this->apiKey,
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
                throw new \RuntimeException("LLM API returned HTTP {$httpCode}: {$response}");
            }

            if ($attempt < $maxAttempts) {
                usleep($backoff[$attempt - 1]);
            }
        }

        if ($error !== '') {
            throw new \RuntimeException("LLM API request failed after {$maxAttempts} attempts: {$error}");
        }

        throw new \RuntimeException("LLM API returned HTTP {$httpCode} after {$maxAttempts} attempts: {$response}");
    }
}
