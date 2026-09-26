<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;

/**
 * Shared behaviour for HTTP embedding providers.
 *
 * Splits input into batches of `batchSize`, delegates each batch to the
 * vendor-specific requestBatch(), and concatenates the vectors in input order.
 */
abstract class AbstractEmbeddingProvider implements EmbeddingProviderInterface
{
    /**
     * @param  string  $model  Default embedding model identifier. Example: "nomic-embed-text"
     * @param  int  $dimensions  Output vector dimensionality. Example: 768
     * @param  int  $timeout  Request timeout in seconds. Example: 30
     * @param  int  $batchSize  Maximum texts per API request. Example: 100
     */
    public function __construct(
        protected string $model,
        protected int $dimensions,
        protected int $timeout,
        protected int $batchSize,
    ) {}

    /**
     * Embed a single text.
     *
     * @param  string  $text  Input text. Example: "What is Q3 revenue?"
     * @param  string|null  $model  Model override. Example: null
     * @return array<float> The embedding vector. Example: [0.012, -0.034, ...]
     *
     * @throws \RuntimeException When the API fails or returns no vector
     */
    public function embed(string $text, ?string $model = null): array
    {
        return $this->embedBatch([$text], $model)[0]
            ?? throw new \RuntimeException(static::class.' returned an empty result');
    }

    /**
     * Embed many texts, batching requests by batchSize.
     *
     * @param  array<string>  $texts  Input texts. Example: ["chunk one", "chunk two"]
     * @param  string|null  $model  Model override. Example: null
     * @return array<array<float>> One vector per input text, in input order
     *
     * @throws \RuntimeException When any batch request fails
     */
    public function embedBatch(array $texts, ?string $model = null): array
    {
        $vectors = [];

        foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
            array_push($vectors, ...$this->requestBatch($batch, $model ?? $this->model));
        }

        return $vectors;
    }

    /**
     * @return int Output vector dimensionality. Example: 768
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @return string Default model identifier. Example: "nomic-embed-text"
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Embed one batch (at most batchSize texts) with the vendor API.
     *
     * @param  array<string>  $batch  Input texts. Example: ["chunk one", "chunk two"]
     * @param  string  $model  Model identifier. Example: "nomic-embed-text"
     * @return array<array<float>> One vector per input text, in input order
     *
     * @throws \RuntimeException On API failure or unexpected response structure
     */
    abstract protected function requestBatch(array $batch, string $model): array;
}
