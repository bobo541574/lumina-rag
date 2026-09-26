<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use App\Support\HttpJsonClient;

/**
 * Ollama embedding provider.
 *
 * Calls a local or remote Ollama server's `/api/embed` endpoint, which
 * accepts a batch of inputs. No authentication is needed.
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class OllamaEmbeddingProvider extends AbstractEmbeddingProvider
{
    /**
     * @param  string  $baseUrl  Ollama server URL. Example: "http://localhost:11434"
     * @param  string  $model  Embedding model identifier. Example: "nomic-embed-text"
     * @param  int  $dimensions  Output vector dimensionality. Example: 768
     * @param  int  $timeout  Request timeout in seconds. Example: 30
     * @param  int  $batchSize  Maximum texts per API batch. Example: 100
     */
    public function __construct(
        private string $baseUrl = 'http://localhost:11434',
        string $model = 'nomic-embed-text',
        int $dimensions = 768,
        int $timeout = 30,
        int $batchSize = 100,
    ) {
        parent::__construct($model, $dimensions, $timeout, $batchSize);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    protected function requestBatch(array $batch, string $model): array
    {
        $data = HttpJsonClient::post(
            $this->baseUrl.'/api/embed',
            [],
            ['model' => $model, 'input' => $batch],
            $this->timeout,
            'Ollama embedding',
        );

        if (! isset($data['embeddings']) || ! is_array($data['embeddings'])) {
            throw new \RuntimeException('Ollama embedding API returned unexpected response structure');
        }

        return $data['embeddings'];
    }
}
