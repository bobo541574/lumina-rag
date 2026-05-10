<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Generator;
use Modules\LLMModule\Contracts\LLMProviderInterface;

class OllamaLLMProvider implements LLMProviderInterface
{
    private string $baseUrl;

    private string $model;

    private int $timeout;

    public function __construct(
        string $baseUrl = 'http://localhost:11434',
        string $model = 'llama3.2',
        int $timeout = 60,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
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
            'stream' => false,
            'options' => [
                'temperature' => $temperature,
            ],
        ];

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

        $url = $this->baseUrl . '/api/chat';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$content): int {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $parsed = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $delta = $parsed['message']['content'] ?? '';
                    if ($delta !== '') {
                        echo $delta;
                        ob_flush();
                        flush();
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);
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
        $url = $this->baseUrl . '/api/chat';

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

        if ($error !== '') {
            throw new \RuntimeException("Ollama LLM request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Ollama LLM returned HTTP {$httpCode}: {$response}");
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}
