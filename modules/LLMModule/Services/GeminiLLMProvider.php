<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use App\Support\HttpJsonClient;
use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

/**
 * Google Gemini LLM provider using raw cURL.
 *
 * Communicates with the Gemini API (https://generativelanguage.googleapis.com)
 * for both synchronous and streaming text generation. Uses the native Gemini
 * generateContent endpoint with system instructions passed via the
 * systemInstruction field.
 *
 * Streaming parses SSE data: lines via StreamsHttpLines; the non-streaming
 * path retries transient failures via HttpJsonClient.
 *
 * @param  string  $apiKey  Google API key for authentication. Example: "AIza..."
 * @param  string  $model  Gemini model identifier. Example: "gemini-2.5-flash"
 * @param  int  $timeout  cURL request timeout in seconds. Example: 60
 * @param  string  $baseUrl  API base URL. Example: "https://generativelanguage.googleapis.com/v1beta"
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class GeminiLLMProvider implements LLMProviderInterface
{
    use StreamsHttpLines;

    /** @var string Google API key */
    private string $apiKey;

    /** @var string Gemini model name (e.g. "gemini-2.5-flash") */
    private string $model;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /** @var string API base URL */
    private string $baseUrl;

    /**
     * @param  string  $apiKey  Google API key. Example: "AIza..."
     * @param  string  $model  Model identifier. Example: "gemini-2.5-flash"
     * @param  int  $timeout  Request timeout in seconds. Example: 60
     * @param  string  $baseUrl  API base URL. Example: "https://generativelanguage.googleapis.com/v1beta"
     */
    public function __construct(
        string $apiKey,
        string $model = 'gemini-2.5-flash',
        int $timeout = 60,
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Perform a synchronous LLM completion via the Gemini API.
     *
     * Sends the system prompt as systemInstruction and the user prompt as
     * a user message. Returns an LLMResponse with content and token counts.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant."
     * @param  string  $userPrompt  The assembled user prompt. Example: "Context:\n---\n...\n---\n\nQuestion: What is X?\n\nAnswer:"
     * @param  array  $options  Override options. Example: ["temperature" => 0.3, "max_tokens" => 4096, "model" => "gemini-2.5-flash"]
     * @return LLMResponse Response with content and token usage. Example: new LLMResponse("The answer is...", 150, 50, 200, "gemini-2.5-flash", "stop")
     *
     * @throws \RuntimeException When the API returns no candidates, HTTP 4xx, or repeated 5xx
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): LLMResponse
    {
        $temperature = (float) ($options['temperature'] ?? 0.3);
        $model = $options['model'] ?? $this->model;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                    'role' => 'user',
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
            ],
        ];

        if (isset($options['max_tokens'])) {
            $payload['generationConfig']['maxOutputTokens'] = $options['max_tokens'];
        }

        if ($systemPrompt !== '') {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ];
        }

        $response = $this->sendRequest($model, $payload);

        $candidate = $response['candidates'][0] ?? throw new \RuntimeException('Gemini API returned no candidates');

        $text = '';
        if (isset($candidate['content']['parts'])) {
            foreach ($candidate['content']['parts'] as $part) {
                $text .= $part['text'] ?? '';
            }
        }

        $usage = $response['usageMetadata'] ?? [];

        return new LLMResponse(
            content: $text,
            promptTokens: $usage['promptTokenCount'] ?? 0,
            completionTokens: $usage['candidatesTokenCount'] ?? 0,
            totalTokens: $usage['totalTokenCount'] ?? 0,
            model: $model,
            finishReason: $candidate['finishReason'] ?? null,
        );
    }

    /**
     * Perform a streaming LLM completion via the Gemini API.
     *
     * Uses StreamsHttpLines to stream SSE events. Parses the Gemini
     * streaming format (text/event-stream with data: JSON lines) and
     * yields content text deltas.
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
        $model = $options['model'] ?? $this->model;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                    'role' => 'user',
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
            ],
        ];

        if (isset($options['max_tokens'])) {
            $payload['generationConfig']['maxOutputTokens'] = $options['max_tokens'];
        }

        if ($systemPrompt !== '') {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ];
        }

        $url = $this->baseUrl.'/models/'.urlencode($model).':streamGenerateContent?alt=sse';

        yield from $this->streamLines(
            $url,
            [
                'Content-Type: application/json',
                // Credentials travel in a header, never in the URL (ISO 27002:8.24).
                'x-goog-api-key: '.$this->apiKey,
            ],
            $payload,
            $this->timeout,
            'Gemini',
            static function (string $line): array {
                if (! str_starts_with($line, 'data: ')) {
                    return [];
                }

                $parsed = json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);

                return array_map(
                    static fn (array $part): string => (string) ($part['text'] ?? ''),
                    $parsed['candidates'][0]['content']['parts'] ?? [],
                );
            },
        );
    }

    /**
     * Return the configured model name identifier.
     *
     * @return string Model name. Example: "gemini-2.5-flash"
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
     * Execute a single HTTP request to the Gemini generateContent endpoint.
     *
     * Retries transient failures via HttpJsonClient; 4xx errors throw immediately.
     *
     * @param  string  $model  Model identifier. Example: "gemini-2.5-flash"
     * @param  array  $payload  Request payload. Example: ["contents" => [...], "generationConfig" => [...]]
     * @return array Decoded response array. Example: ["candidates" => [...], "usageMetadata" => [...]]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed JSON
     */
    private function sendRequest(string $model, array $payload): array
    {
        return HttpJsonClient::post(
            $this->baseUrl.'/models/'.urlencode($model).':generateContent',
            // Credentials travel in a header, never in the URL (ISO 27002:8.24).
            ['x-goog-api-key: '.$this->apiKey],
            $payload,
            $this->timeout,
            'Gemini',
        );
    }
}
