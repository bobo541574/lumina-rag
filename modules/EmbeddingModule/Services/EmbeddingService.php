<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;

/**
 * Caching wrapper around embedding providers.
 *
 * Caches embeddings by MD5 hash of the input text to avoid redundant
 * API calls. Supports single and batch embedding with automatic cache
 * hit detection. Cache keys follow the pattern "embedding:{model}:{hash}"
 * and live in the configured Laravel cache store (Redis in production).
 *
 * When embedBatch is called, the service checks the cache for each text
 * independently and only dispatches API calls for texts that are not yet
 * cached. Results are reassembled in their original order.
 *
 * @param  EmbeddingProviderInterface  $provider  The underlying embedding provider used for cache misses. Example: mock(EmbeddingProviderInterface::class)
 * @param  CacheRepository  $cache  Laravel cache store for embedding results. Example: $app->make(CacheRepository::class)
 * @param  int  $cacheTtl  Cache TTL in seconds (default 86400 = 24 hours). Example: 3600
 *
 * @throws \RuntimeException Propagated from provider when API calls fail
 */
class EmbeddingService implements EmbeddingServiceInterface
{
    /** @var EmbeddingProviderInterface Underlying provider for cache misses */
    private EmbeddingProviderInterface $provider;

    /** @var CacheRepository Laravel cache store */
    private CacheRepository $cache;

    /** @var int Cache TTL in seconds (default 86400) */
    private int $cacheTtl;

    /**
     * @param  EmbeddingProviderInterface  $provider  Underlying embedding provider. Example: new OpenAIEmbeddingProvider(...)
     * @param  CacheRepository  $cache  Laravel cache store. Example: $app->make(CacheRepository::class)
     * @param  int  $cacheTtl  Cache TTL in seconds. Example: 86400
     */
    public function __construct(
        EmbeddingProviderInterface $provider,
        CacheRepository $cache,
        int $cacheTtl = 86400,
    ) {
        $this->provider = $provider;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Embed a single text, returning cached result when available.
     *
     * Generates an MD5 hash of the input text and checks the cache before
     * calling the provider. Cache key format: "embedding:{model}:{hash}".
     *
     * @param  string  $text  Input text to embed. Example: "What is the capital of France?"
     * @param  string|null  $model  Optional model override passed through to provider. Example: "text-embedding-3-small"
     * @return array Float vector from cache or provider. Example: [0.012, -0.034, ..., 0.098]
     *
     * @throws \RuntimeException When the provider call fails or returns empty
     */
    public function embed(string $text, ?string $model = null): array
    {
        $modelName = $model ?? $this->provider->getModelName();
        $hash = md5($text);
        $cacheKey = "embedding:{$modelName}:{$hash}";

        return $this->cache->remember($cacheKey, $this->cacheTtl, fn (): array => $this->provider->embed($text, $model));
    }

    /**
     * Embed multiple texts, using cached results and only hitting the API
     * for texts not yet cached.
     *
     * For each text, the service checks the cache individually. Uncachedexts
     * are sent to the provider in a single batch, then each new vector is
     * stored in the cache before returning all results in original order.
     *
     * @param  array  $texts  Array of text strings to embed. Example: ["first chunk", "second chunk", "third chunk"]
     * @param  string|null  $model  Optional model override passed through to provider. Example: "nomic-embed-text:latest"
     * @return array Array of float vectors in original input order. Example: [[0.01, ...], [0.02, ...], [0.03, ...]]
     *
     * @throws \RuntimeException When the provider batch call fails or returns wrong count
     * @throws \InvalidArgumentException When $texts is empty
     */
    public function embedBatch(array $texts, ?string $model = null): array
    {
        $modelName = $model ?? $this->provider->getModelName();
        $hashes = array_map(md5(...), $texts);
        $results = [];
        $uncached = [];
        $uncachedIndices = [];

        foreach ($texts as $i => $text) {
            $cacheKey = "embedding:{$modelName}:{$hashes[$i]}";
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $results[$i] = $cached;
            } else {
                $uncached[$i] = $text;
                $uncachedIndices[] = $i;
            }
        }

        if ($uncached !== []) {
            $newVectors = $this->provider->embedBatch(array_values($uncached), $model);
            $vecIndex = 0;
            foreach ($uncachedIndices as $i) {
                $vector = $newVectors[$vecIndex];
                $cacheKey = "embedding:{$modelName}:{$hashes[$i]}";
                $this->cache->put($cacheKey, $vector, $this->cacheTtl);
                $results[$i] = $vector;
                $vecIndex++;
            }
        }

        ksort($results);

        return array_values($results);
    }
}
