<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;

/**
 * Google Gemini embedding provider using raw cURL.
 *
 * Converts text strings to vector embeddings via the Gemini Embedding API
 * (https://generativelanguage.googleapis.com/v1beta/models/{model}:batchEmbedContents).
 * Supports batched requests and implements exponential-backoff retry (3 attempts)
 * for transient errors.
 *
 * Authentication is via API key passed as a query parameter. The provider
 * respects per-model configuration for model name, dimensions, timeout,
 * and batch size.
 *
 * @param  string  $apiKey  Google API key for authentication. Example: "AIza..."
 * @param  string  $model  Embedding model identifier. Example: "text-embedding-004"
 * @param  int  $dimensions  Output vector dimensionality. Example: 768
 * @param  int  $timeout  cURL request timeout in seconds. Example: 30
 * @param  int  $batchSize  Maximum texts per API batch. Example: 100
 * @param  string  $baseUrl  API base URL. Example: "https://generativelanguage.googleapis.com/v1beta"
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class GeminiEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @var string Google API key */
    private string $apiKey;

    /** @var string Embedding model name (e.g. "text-embedding-004") */
    private string $model;

    /** @var int Output vector dimensions */
    private int $dimensions;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /** @var int Maximum number of texts per single API call */
    private int $batchSize;

    /** @var string API base URL */
    private string $baseUrl;

    /**
     * @param  string  $apiKey  Google API key. Example: "AIza..."
     * @param  string  $model  Model identifier. Example: "text-embedding-004"
     * @param  int  $dimensions  Vector dimensions. Example: 768
     * @param  int  $timeout  Request timeout in seconds. Example: 30
     * @param  int  $batchSize  Batch size for API calls. Example: 100
     * @param  string  $baseUrl  API base URL. Example: "https://generativelanguage.googleapis.com/v1beta"
     */
    public function __construct(
        string $apiKey,
        string $model = 'text-embedding-004',
        int $dimensions = 768,
        int $timeout = 30,
        int $batchSize = 100,
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->dimensions = $dimensions;
        $this->timeout = $timeout;
        $this->batchSize = $batchSize;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Embed a single text string via the Gemini Embedding API.
     *
     * Delegates to embedBatch with a single-element array and returns the
     * first (and only) vector result.
     *
     * @param  string  $text  Input text to embed. Example: "What is the capital of France?"
     * @param  string|null  $model  Optional model override. Example: "text-embedding-004"
     * @return array Float vector. Example: [0.012, -0.034, ..., 0.098]
     *
     * @throws \RuntimeException When the API returns an empty result or errors
     */
    public function embed(string $text, ?string $model = null): array
    {
        $results = $this->embedBatch([$text], $model);

        return $results[0] ?? throw new \RuntimeException('Gemini embedding API returned empty result');
    }

    /**
     * Embed multiple texts in batched API calls.
     *
     * Texts are split into batches of batchSize and sent via
     * batchEmbedContents sequentially. Results from all batches are merged
     * in input order.
     *
     * @param  array  $texts  Array of texts to embed. Example: ["text A", "text B"]
     * @param  string|null  $model  Optional model override. Example: "text-embedding-004"
     * @return array Array of float vectors in input order. Example: [[0.01, ...], [0.02, ...]]
     *
     * @throws \RuntimeException When any API call fails or returns unexpected data
     */
    public function embedBatch(array $texts, ?string $model = null): array
    {
        $modelName = $model ?? $this->model;
        $chunks = array_chunk($texts, $this->batchSize);
        $allVectors = [];

        foreach ($chunks as $batch) {
            $response = $this->sendRequest($batch, $modelName);
            $allVectors = array_merge($allVectors, $response);
        }

        return $allVectors;
    }

    /**
     * Return the vector dimension count for the configured model.
     *
     * @return int Number of dimensions. Example: 768
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Return the configured model name identifier.
     *
     * @return string Model name. Example: "text-embedding-004"
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Execute a single HTTP request to the Gemini batchEmbedContents endpoint.
     *
     * Implements retry with exponential backoff (1s, 5s, 25s) for
     * transient server errors. Client errors (4xx) throw immediately.
     *
     * @param  array  $batch  Batch of texts to embed. Example: ["text1", "text2"]
     * @param  string  $modelName  Model identifier. Example: "text-embedding-004"
     * @return array Array of embedding vectors in input order. Example: [[0.01, ...], [0.02, ...]]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed response
     */
    private function sendRequest(array $batch, string $modelName): array
    {
        $maxAttempts = 3;
        $backoff = [1_000_000, 5_000_000, 25_000_000];

        $requests = array_map(fn (string $text): array => [
            'model' => "models/{$modelName}",
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
        ], $batch);

        $payload = ['requests' => $requests];
        $url = $this->baseUrl.'/models/'.urlencode($modelName).':batchEmbedContents?key='.$this->apiKey;

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
                    throw new \RuntimeException('Gemini embedding API returned unexpected response structure');
                }

                return array_map(
                    fn (array $item): array => $item['values'] ?? throw new \RuntimeException('Gemini embedding missing values'),
                    $data['embeddings'],
                );
            }

            if ($httpCode >= 400 && $httpCode < 500) {
                throw new \RuntimeException("Gemini embedding API returned HTTP {$httpCode}: {$response}");
            }

            if ($attempt < $maxAttempts) {
                usleep($backoff[$attempt - 1]);
            }
        }

        if ($error !== '') {
            throw new \RuntimeException("Gemini embedding API request failed after {$maxAttempts} attempts: {$error}");
        }

        throw new \RuntimeException("Gemini embedding API returned HTTP {$httpCode} after {$maxAttempts} attempts: {$response}");
    }
}
