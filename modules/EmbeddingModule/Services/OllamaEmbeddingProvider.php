<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;

class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    private string $baseUrl;

    private string $model;

    private int $dimensions;

    private int $timeout;

    private int $batchSize;

    public function __construct(
        string $baseUrl = 'http://localhost:11434',
        string $model = 'nomic-embed-text',
        int $dimensions = 768,
        int $timeout = 30,
        int $batchSize = 100,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->dimensions = $dimensions;
        $this->timeout = $timeout;
        $this->batchSize = $batchSize;
    }

    public function embed(string $text, ?string $model = null): array
    {
        $results = $this->callApi([$text], $model);

        return $results[0] ?? throw new \RuntimeException('Ollama embedding API returned empty result');
    }

    public function embedBatch(array $texts, ?string $model = null): array
    {
        return $this->callApi($texts, $model);
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    private function callApi(array $texts, ?string $model = null): array
    {
        $chunks = array_chunk($texts, $this->batchSize);
        $allVectors = [];

        foreach ($chunks as $batch) {
            $response = $this->sendRequest($batch, $model);
            $allVectors = array_merge($allVectors, $response);
        }

        return $allVectors;
    }

    private function sendRequest(array $batch, ?string $model = null): array
    {
        $maxAttempts = 3;
        $backoff = [1_000_000, 5_000_000, 25_000_000];

        $url = $this->baseUrl.'/api/embed';
        $payload = [
            'model' => $model ?? $this->model,
            'input' => $batch,
        ];

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
                $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

                if (! isset($data['embeddings']) || ! is_array($data['embeddings'])) {
                    throw new \RuntimeException('Ollama embedding API returned unexpected response structure');
                }

                return $data['embeddings'];
            }

            if ($httpCode >= 400 && $httpCode < 500) {
                throw new \RuntimeException("Ollama embedding returned HTTP {$httpCode}: {$response}");
            }

            if ($attempt < $maxAttempts) {
                usleep($backoff[$attempt - 1]);
            }
        }

        if ($error !== '') {
            throw new \RuntimeException("Ollama embedding request failed after {$maxAttempts} attempts: {$error}");
        }

        throw new \RuntimeException("Ollama embedding returned HTTP {$httpCode} after {$maxAttempts} attempts: {$response}");
    }
}
