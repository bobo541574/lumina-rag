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
 * hit detection.
 */
class EmbeddingService implements EmbeddingServiceInterface
{
    private EmbeddingProviderInterface $provider;

    private CacheRepository $cache;

    /** @var int Cache TTL in seconds */
    private int $cacheTtl;

    /**
     * @param  EmbeddingProviderInterface  $provider  Underlying provider
     * @param  CacheRepository  $cache  Cache store
     * @param  int  $cacheTtl  Cache TTL in seconds
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
     * @param  string  $text  Input text
     * @return array Float vector
     */
    public function embed(string $text): array
    {
        $hash = md5($text);
        $cacheKey = "embedding:{$this->provider->getModelName()}:{$hash}";

        return $this->cache->remember($cacheKey, $this->cacheTtl, fn (): array => $this->provider->embed($text));
    }

    /**
     * Embed multiple texts, using cached results and only hitting the API
     * for texts not yet cached.
     *
     * @param  array  $texts  Array of text strings
     * @return array Array of float vectors in original order
     */
    public function embedBatch(array $texts): array
    {
        $hashes = array_map(md5(...), $texts);
        $results = [];
        $uncached = [];
        $uncachedIndices = [];

        foreach ($texts as $i => $text) {
            $cacheKey = "embedding:{$this->provider->getModelName()}:{$hashes[$i]}";
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $results[$i] = $cached;
            } else {
                $uncached[$i] = $text;
                $uncachedIndices[] = $i;
            }
        }

        if ($uncached !== []) {
            $newVectors = $this->provider->embedBatch(array_values($uncached));
            $vecIndex = 0;
            foreach ($uncachedIndices as $i) {
                $vector = $newVectors[$vecIndex];
                $cacheKey = "embedding:{$this->provider->getModelName()}:{$hashes[$i]}";
                $this->cache->put($cacheKey, $vector, $this->cacheTtl);
                $results[$i] = $vector;
                $vecIndex++;
            }
        }

        ksort($results);

        return array_values($results);
    }
}
