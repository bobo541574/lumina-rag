<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Contracts;

/**
 * Embedding Provider Interface
 *
 * Defines the contract that every embedding provider (OpenAI, Ollama, etc.)
 * must implement. Each provider is responsible for translating text strings
 * into dense vector representations via its respective API.
 *
 * Providers are instantiated by ProviderFactory and consumed by EmbeddingService,
 * which adds caching on top. Implementations should handle their own retry logic,
 * timeouts, and error mapping.
 *
 * @method array embed(string $text, ?string $model = null) Single text embedding. Example: $provider->embed("Hello world")
 * @method array embedBatch(array $texts, ?string $model = null) Batch embedding. Example: $provider->embedBatch(["a", "b"])
 */
interface EmbeddingProviderInterface
{
    /**
     * Embed a single text string into a vector.
     *
     * @param  string  $text  The text to embed. Example: "What is the capital of France?"
     * @param  string|null  $model  Optional model override. Example: "nomic-embed-text:latest"
     * @return array Float vector. Example: [0.0123, -0.0456, ..., 0.0789]
     *
     * @throws \RuntimeException When the API call fails or returns zero embeddings
     */
    public function embed(string $text, ?string $model = null): array;

    /**
     * Embed multiple texts in a single API batch.
     *
     * @param  array  $texts  Array of texts to embed. Example: ["text A", "text B"]
     * @param  string|null  $model  Optional model override. Example: "text-embedding-3-small"
     * @return array Array of float vectors in input order. Example: [[0.01, ...], [0.02, ...]]
     *
     * @throws \RuntimeException When the API call fails or returns fewer embeddings than requested
     * @throws \InvalidArgumentException When $texts is empty
     */
    public function embedBatch(array $texts, ?string $model = null): array;

    /**
     * Return the dimensionality of vectors produced by this provider.
     *
     * @return int Number of dimensions. Example: 1536
     */
    public function getDimensions(): int;

    /**
     * Return the canonical model name identifier.
     *
     * @return string Model name. Example: "text-embedding-ada-002"
     */
    public function getModelName(): string;
}
