<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use App\Support\HttpJsonClient;

/**
 * Google Gemini embedding provider.
 *
 * Uses the `batchEmbedContents` endpoint. The API key travels in the
 * `x-goog-api-key` header, never in the URL (ISO 27002:8.24), so it cannot
 * leak into access logs or error messages.
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class GeminiEmbeddingProvider extends AbstractEmbeddingProvider
{
    /**
     * @param  string  $apiKey  Google API key. Example: "AIza..."
     * @param  string  $model  Embedding model identifier. Example: "text-embedding-004"
     * @param  int  $dimensions  Output vector dimensionality. Example: 768
     * @param  int  $timeout  Request timeout in seconds. Example: 30
     * @param  int  $batchSize  Maximum texts per API batch. Example: 100
     * @param  string  $baseUrl  API base URL. Example: "https://generativelanguage.googleapis.com/v1beta"
     */
    public function __construct(
        private string $apiKey,
        string $model = 'text-embedding-004',
        int $dimensions = 768,
        int $timeout = 30,
        int $batchSize = 100,
        private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
    ) {
        parent::__construct($model, $dimensions, $timeout, $batchSize);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    protected function requestBatch(array $batch, string $model): array
    {
        $requests = array_map(fn (string $text): array => [
            'model' => "models/{$model}",
            'content' => ['parts' => [['text' => $text]]],
        ], $batch);

        $data = HttpJsonClient::post(
            $this->baseUrl.'/models/'.urlencode($model).':batchEmbedContents',
            ['x-goog-api-key: '.$this->apiKey],
            ['requests' => $requests],
            $this->timeout,
            'Gemini embedding',
        );

        if (! isset($data['embeddings']) || ! is_array($data['embeddings'])) {
            throw new \RuntimeException('Gemini embedding API returned unexpected response structure');
        }

        return array_map(
            fn (array $item): array => $item['values'] ?? throw new \RuntimeException('Gemini embedding missing values'),
            $data['embeddings'],
        );
    }
}
