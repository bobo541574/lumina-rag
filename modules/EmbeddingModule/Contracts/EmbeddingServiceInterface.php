<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Contracts;

/**
 * Embedding Service Interface
 *
 * Defines the contract for text-to-vector embedding services. Implementations
 * wrap one or more embedding providers and may add caching, batching, and
 * retry logic. The primary consumer is the ChatModule's RAG pipeline.
 *
 * @method array embed(string $text, ?string $model = null) Single text to vector. Example: $service->embed("What is RAG?")
 * @method array embedBatch(array $texts, ?string $model = null) Multiple texts to vectors in order. Example: $service->embedBatch(["text1", "text2"])
 *
 * @throws \RuntimeException When the underlying provider returns an error or empty result
 */
interface EmbeddingServiceInterface
{
    /**
     * Embed a single text string into a vector.
     *
     * @param  string  $text  The input text to embed. Example: "What is the capital of France?"
     * @param  string|null  $model  Optional model override. Example: "text-embedding-3-small"
     * @return array Float vector of dimension matching the active model. Example: [0.012, -0.034, ..., 0.098]
     *
     * @throws \RuntimeException When the provider returns an error or empty result
     */
    public function embed(string $text, ?string $model = null): array;

    /**
     * Embed multiple text strings in a single batch.
     *
     * @param  array  $texts  Array of input texts. Example: ["first text", "second text"]
     * @param  string|null  $model  Optional model override. Example: "text-embedding-3-small"
     * @return array Array of float vectors in the same order as input. Example: [[0.01, ...], [0.02, ...]]
     *
     * @throws \RuntimeException When the provider returns an error or empty result
     * @throws \InvalidArgumentException When any text is empty
     */
    public function embedBatch(array $texts, ?string $model = null): array;
}
