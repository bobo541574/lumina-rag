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

        if (isset($options['max_tokens'])) {
            $payload['options']['num_predict'] = $options['max_tokens'];
        }

        $url = $this->baseUrl.'/api/chat';

        $queue = [];
        $lineBuffer = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
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
