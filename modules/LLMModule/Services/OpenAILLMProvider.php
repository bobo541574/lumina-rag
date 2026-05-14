<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

class OpenAILLMProvider implements LLMProviderInterface
{
    private string $apiKey;

    private string $model;

    private int $timeout;

    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        int $timeout = 60,
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    public function complete(string $systemPrompt, string $userPrompt, array $options = []): LLMResponse
    {
        $temperature = (float) ($options['temperature'] ?? 0.3);

        $payload = [
            'model' => $this->model,
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

    public function completeStream(string $systemPrompt, string $userPrompt, array $options = []): Generator
    {
        $temperature = (float) ($options['temperature'] ?? 0.3);

        $payload = [
            'model' => $this->model,
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

        $deltas = [];

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
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$deltas): int {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if ($line === 'data: [DONE]') {
                        return strlen($data);
                    }
                    if (str_starts_with($line, 'data: ')) {
                        $json = substr($line, 6);
                        $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                        $delta = $parsed['choices'][0]['delta']['content'] ?? '';
                        if ($delta !== '') {
                            $deltas[] = $delta;
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);

        yield from $deltas;
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function countTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

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
