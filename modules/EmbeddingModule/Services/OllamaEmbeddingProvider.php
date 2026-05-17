<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;

/**
 * Ollama embedding provider using raw cURL.
 *
 * Converts text strings to vector embeddings via a local Ollama instance's
 * /api/embed endpoint. Supports batched requests for cost efficiency and
 * implements exponential-backoff retry (3 attempts) for transient errors.
 *
 * Requires a running Ollama server (default http://localhost:11434) with
 * the configured embedding model pulled (e.g. nomic-embed-text:latest).
 * No authentication is needed for local Ollama instances.
 *
 * @param  string  $baseUrl  Ollama server base URL. Example: "http://localhost:11434"
 * @param  string  $model  Embedding model name. Example: "nomic-embed-text:latest"
 * @param  int  $dimensions  Output vector dimensionality. Example: 768
 * @param  int  $timeout  cURL request timeout in seconds. Example: 30
 * @param  int  $batchSize  Maximum texts per API batch. Example: 100
 *
 * @throws \RuntimeException On API failure, empty response, or unexpected structure
 */
class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @var string Ollama server base URL (trailing slash stripped) */
    private string $baseUrl;

    /** @var string Embedding model name (e.g. "nomic-embed-text:latest") */
    private string $model;

    /** @var int Output vector dimensions */
    private int $dimensions;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /** @var int Maximum number of texts per single API call */
    private int $batchSize;

    /**
     * @param  string  $baseUrl  Ollama server base URL. Example: "http://localhost:11434"
     * @param  string  $model  Embedding model name. Example: "nomic-embed-text:latest"
     * @param  int  $dimensions  Vector dimensions. Example: 768
     * @param  int  $timeout  Request timeout in seconds. Example: 30
     * @param  int  $batchSize  Batch size for API calls. Example: 100
     */
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

    /**
     * Embed a single text string via the Ollama API.
     *
     * Delegates to callApi with a single-element array and returns the
     * first (and only) vector result.
     *
     * @param  string  $text  Input text to embed. Example: "What is the capital of France?"
     * @param  string|null  $model  Optional model override. Example: "nomic-embed-text:latest"
     * @return array Float vector. Example: [0.012, -0.034, ..., 0.098]
     *
     * @throws \RuntimeException When the API returns an empty result or errors
     */
    public function embed(string $text, ?string $model = null): array
    {
        $results = $this->callApi([$text], $model);

        return $results[0] ?? throw new \RuntimeException('Ollama embedding API returned empty result');
    }

    /**
     * Embed multiple texts in a single (or batched) API call.
     *
     * Texts are split into batches of batchSize and sent sequentially.
     * Results from all batches are merged in input order.
     *
     * @param  array  $texts  Array of texts to embed. Example: ["text A", "text B"]
     * @param  string|null  $model  Optional model override. Example: "nomic-embed-text:latest"
     * @return array Array of float vectors in input order. Example: [[0.01, ...], [0.02, ...]]
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
     * @return int Number of dimensions. Example: 768
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Return the configured model name identifier.
     *
     * @return string Model name. Example: "nomic-embed-text:latest"
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Send texts to the Ollama embeddings API, splitting into batches if needed.
     *
     * Iterates over array_chunk slices and merges all results sequentially.
     *
     * @param  array  $texts  Texts to embed. Example: ["text1", "text2"]
     * @param  string|null  $model  Optional model override. Example: "nomic-embed-text:latest"
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
     * Execute a single HTTP request to the Ollama /api/embed endpoint.
     *
     * Implements retry with exponential backoff (1s, 5s, 25s) for
     * transient server errors. Client errors (4xx) throw immediately.
     *
     * @param  array  $batch  Batch of texts to embed. Example: ["text1", "text2"]
     * @param  string|null  $model  Optional model override. Example: "nomic-embed-text:latest"
     * @return array Array of embedding vectors. Example: [[0.01, ...], [0.02, ...]]
     *
     * @throws \RuntimeException On HTTP 4xx, repeated 5xx, cURL error, or malformed response
     */
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
