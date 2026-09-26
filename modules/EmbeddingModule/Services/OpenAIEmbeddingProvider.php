<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use App\Support\HttpJsonClient;

/**
 * OpenAI-compatible embedding provider.
 *
 * Calls the `/embeddings` endpoint of OpenAI or any compatible host, with
 * bearer-token auth. Vectors are returned sorted by the input index.
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class OpenAIEmbeddingProvider extends AbstractEmbeddingProvider
{
    /**
     * @param  string  $apiKey  API key for bearer authentication. Example: "sk-proj-..."
     * @param  string  $model  Embedding model identifier. Example: "text-embedding-3-small"
     * @param  int  $dimensions  Output vector dimensionality. Example: 1536
     * @param  int  $timeout  Request timeout in seconds. Example: 30
     * @param  int  $batchSize  Maximum texts per API batch. Example: 100
     * @param  string  $baseUrl  API base URL. Example: "https://api.openai.com/v1"
     */
    public function __construct(
        private string $apiKey,
        string $model = 'text-embedding-ada-002',
        int $dimensions = 1536,
        int $timeout = 30,
        int $batchSize = 100,
        private string $baseUrl = 'https://api.openai.com/v1',
    ) {
        parent::__construct($model, $dimensions, $timeout, $batchSize);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    protected function requestBatch(array $batch, string $model): array
    {
        $data = HttpJsonClient::post(
            $this->baseUrl.'/embeddings',
            ['Authorization: Bearer '.$this->apiKey],
            ['model' => $model, 'input' => $batch],
            $this->timeout,
            'OpenAI embedding',
        );

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw new \RuntimeException('OpenAI embedding API returned unexpected response structure');
        }

        usort($data['data'], fn (array $a, array $b): int => $a['index'] - $b['index']);

        return array_map(fn (array $item): array => $item['embedding'], $data['data']);
    }
}
