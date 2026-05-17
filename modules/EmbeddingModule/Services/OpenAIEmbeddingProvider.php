<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;

/**
 * OpenAI embedding provider using raw cURL.
 *
 * Converts text strings to vector embeddings via the OpenAI Embeddings API
 * (https://api.openai.com/v1/embeddings). Supports batched requests for cost
 * efficiency and implements exponential-backoff retry (3 attempts) for
 * transient errors. Returns vectors sorted by the input index order.
 *
 * Authentication is via bearer token. The provider respects per-model
 * configuration for model name, dimensions, timeout, and batch size.
 *
 * @param  string  $apiKey  OpenAI API key for authentication. Example: "sk-proj-..."
 * @param  string  $model  Embedding model identifier. Example: "text-embedding-ada-002"
 * @param  int  $dimensions  Output vector dimensionality. Example: 1536
 * @param  int  $timeout  cURL request timeout in seconds. Example: 30
 * @param  int  $batchSize  Maximum texts per API batch. Example: 100
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @var string OpenAI API key for bearer authentication */
    private string $apiKey;

    /** @var string Embedding model name (e.g. "text-embedding-ada-002") */
    private string $model;

    /** @var int Output vector dimensions */
    private int $dimensions;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /** @var int Maximum number of texts per single API call */
    private int $batchSize;

    /**
     * @param  string  $apiKey  OpenAI API key. Example: "sk-proj-..."
     * @param  string  $model  Model identifier. Example: "text-embedding-ada-002"
     * @param  int  $dimensions  Vector dimensions. Example: 1536
     * @param  int  $timeout  Request timeout in seconds. Example: 30
     * @param  int  $batchSize  Batch size for API calls. Example: 100
     */
    public function __construct(
        string $apiKey,
        string $model = 'text-embedding-ada-002',
        int $dimensions = 1536,
        int $timeout = 30,
        int $batchSize = 100,
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->dimensions = $dimensions;
        $this->timeout = $timeout;
        $this->batchSize = $batchSize;
    }

    /**
     * Embed a single text string via the OpenAI API.
     *
     * Delegates to callApi with a single-element array and returns the
     * first (and only) vector result.
     *
     * @param  string  $text  Input text to embed. Example: "What is the capital of France?"
     * @param  string|null  $model  Optional model override. Example: "text-embedding-3-small"
     * @return array Float vector. Example: [0.012, -0.034, ..., 0.098]
     *
     * @throws \RuntimeException When the API returns an empty result or errors
     */
    public function embed(string $text, ?string $model = null): array
    {
        $results = $this->callApi([$text], $model);

        return $results[0] ?? throw new \RuntimeException('Embedding API returned empty result');
    }

    /**
     * Embed multiple texts in a single (or batched) API call.
     *
     * Texts are split into batches of batchSize and sent sequentially.
     * Results from all batches are merged in input order.
     *
     * @param  array  $texts  Array of texts to embed. Example: ["text A", "text B", "text C"]
     * @param  string|null  $model  Optional model override. Example: "text-embedding-3-small"
     * @return array Array of float vectors in input order. Example: [[0.01, ...], [0.02, ...], [0.03, ...]]
     *
     * @throws \RuntimeException When any API call fails or returns unexpected data
     */
    public function embedBatch(array $texts, ?string $model = null): array
    {
        return $this->callApi($texts, $model);
    }

    /**
     * Return the vector dimension count for the configured model.
     *
     * @return int Number of dimensions. Example: 1536
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Return the configured model name identifier.
     *
     * @return string Model name. Example: "text-embedding-ada-002"
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Send texts to the OpenAI Embeddings API, splitting into batches if needed.
     *
     * Iterates over array_chunk slices and merges all results sequentially.
     *
     * @param  array  $texts  Texts to embed. Example: ["text1", "text2"]
     * @param  string|null  $model  Optional model override. Example: "text-embedding-3-small"
     * @return array Array of embedding vectors in input order. Example: [[0.01, ...], [0.02, ...]]
     *
     * @throws \RuntimeException When any sendRequest call fails
     */
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

    /**
     * Execute a single HTTP request to the OpenAI Embeddings API.
     *
     * Implements retry with exponential backoff (1s, 5s, 25s) for
     * transient server errors. Client errors (4xx) throw immediately.
     *
     * @param  array  $batch  Batch of texts to embed. Example: ["text1", "text2"]
     * @param  string|null  $model  Optional model override. Example: "text-embedding-3-small"
     * @return array Array of embedding vectors sorted by index. Example: [[0.01, ...], [0.02, ...]]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed response
     */
    private function sendRequest(array $batch, ?string $model = null): array
    {
        $maxAttempts = 3;
        $backoff = [1_000_000, 5_000_000, 25_000_000];

        $url = 'https://api.openai.com/v1/embeddings';
        $payload = [
            'model' => $model ?? $this->model,
            'input' => $batch,
        ];

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
                $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                if (! isset($data['data']) || ! is_array($data['data'])) {
                    throw new \RuntimeException('Embedding API returned unexpected response structure');
                }

                usort($data['data'], fn (array $a, array $b): int => $a['index'] - $b['index']);

                return array_map(fn (array $item): array => $item['embedding'], $data['data']);
            }

            if ($httpCode >= 400 && $httpCode < 500) {
                throw new \RuntimeException("Embedding API returned HTTP {$httpCode}: {$response}");
            }

            if ($attempt < $maxAttempts) {
                usleep($backoff[$attempt - 1]);
            }
        }

        if ($error !== '') {
            throw new \RuntimeException("Embedding API request failed after {$maxAttempts} attempts: {$error}");
        }

        throw new \RuntimeException("Embedding API returned HTTP {$httpCode} after {$maxAttempts} attempts: {$response}");
    }
}
