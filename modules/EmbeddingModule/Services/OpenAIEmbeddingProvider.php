<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;

/**
 * OpenAI embedding provider using raw cURL.
 *
 * Converts text strings to vector embeddings via the OpenAI Embeddings API.
 * Supports batched requests for cost efficiency.
 */
class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @var string OpenAI API key */
    private string $apiKey;

    /** @var string Embedding model name */
    private string $model;

    /** @var int Output vector dimensions */
    private int $dimensions;

    /** @var int cURL request timeout in seconds */
    private int $timeout;

    /** @var int Maximum texts per API batch */
    private int $batchSize;

    /**
     * @param  string  $apiKey  OpenAI API key
     * @param  string  $model  Model identifier
     * @param  int  $dimensions  Vector dimensions
     * @param  int  $timeout  Request timeout in seconds
     * @param  int  $batchSize  Batch size for API calls
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
     * Embed a single text string.
     *
     * @param  string  $text  Input text
     * @return array Float vector array
     *
     * @throws \RuntimeException On API failure or empty response
     */
    public function embed(string $text): array
    {
        $results = $this->callApi([$text]);

        return $results[0] ?? throw new \RuntimeException('Embedding API returned empty result');
    }

    /**
     * Embed multiple texts in a single batched API call.
     *
     * @param  array  $texts  Array of text strings
     * @return array Array of float vectors in input order
     */
    public function embedBatch(array $texts): array
    {
        return $this->callApi($texts);
    }

    /**
     * Get the vector dimension count for this model.
     *
     * @return int Dimension count
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Get the model name identifier.
     *
     * @return string Model name
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Send texts to the OpenAI API, splitting into batches.
     *
     * @param  array  $texts  Texts to embed
     * @return array Array of embedding vectors
     */
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

    /**
     * Execute the HTTP request to the OpenAI Embeddings API.
     *
     * @param  array  $batch  Batch of texts to embed
     * @return array Array of embedding vectors
     *
     * @throws \RuntimeException On HTTP or JSON parse errors
     */
    private function sendRequest(array $batch): array
    {
        $maxAttempts = 3;
        $backoff = [1_000_000, 5_000_000, 25_000_000];

        $url = 'https://api.openai.com/v1/embeddings';
        $payload = [
            'model' => $this->model,
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
