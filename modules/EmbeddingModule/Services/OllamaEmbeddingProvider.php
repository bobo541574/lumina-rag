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
        info([$baseUrl, $model, $dimensions, $timeout, $batchSize]);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->dimensions = $dimensions;
        $this->timeout = $timeout;
        $this->batchSize = $batchSize;
    }

    public function embed(string $text): array
    {
        $results = $this->callApi([$text]);

        return $results[0] ?? throw new \RuntimeException('Ollama embedding API returned empty result');
    }

    public function embedBatch(array $texts): array
    {
        return $this->callApi($texts);
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    private function callApi(array $texts): array
    {
        $chunks = array_chunk($texts, $this->batchSize);
        $allVectors = [];

        foreach ($chunks as $batch) {
            $response = $this->sendRequest($batch);
            $allVectors = array_merge($allVectors, $response);
        }

        return $allVectors;
    }

    private function sendRequest(array $batch): array
    {
        $url = $this->baseUrl . '/api/embed';
        $payload = [
            'model' => $this->model,
            'input' => $batch,
        ];

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
            throw new \RuntimeException("Ollama embedding request failed: {$error}");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("Ollama embedding returned HTTP {$httpCode}: {$response}");
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['embeddings']) || !is_array($data['embeddings'])) {
            throw new \RuntimeException('Ollama embedding API returned unexpected response structure');
        }

        return $data['embeddings'];
    }
}
