<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\EmbeddingModule\Contracts\EmbeddingProviderInterface;
use Modules\EmbeddingModule\Services\EmbeddingService;

test('test_embed_returns_vector_from_provider', function (): void {
    $provider = mock(EmbeddingProviderInterface::class);
    $provider->shouldReceive('getModelName')->andReturn('test-model');
    $provider->shouldReceive('embed')->with('hello', null)->andReturn([0.1, 0.2, 0.3]);

    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $service = new EmbeddingService($provider, $cache, 3600);
    $result = $service->embed('hello');

    expect($result)->toBe([0.1, 0.2, 0.3]);
});

test('test_embedBatch_returns_all_vectors_in_order', function (): void {
    $provider = mock(EmbeddingProviderInterface::class);
    $provider->shouldReceive('getModelName')->andReturn('test-model');
    $provider->shouldReceive('embedBatch')->with(['a', 'b', 'c'], null)->andReturn([[0.1], [0.2], [0.3]]);

    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->times(3)->andReturn(null);
    $cache->shouldReceive('put')->times(3);

    $service = new EmbeddingService($provider, $cache, 3600);
    $result = $service->embedBatch(['a', 'b', 'c']);

    expect($result)->toHaveCount(3);
    expect($result[0])->toBe([0.1]);
    expect($result[1])->toBe([0.2]);
    expect($result[2])->toBe([0.3]);
});

test('test_embedBatch_uses_cache_when_available', function (): void {
    $provider = mock(EmbeddingProviderInterface::class);
    $provider->shouldReceive('getModelName')->andReturn('test-model');
    $provider->shouldNotReceive('embedBatch');

    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->once()->andReturn([0.5, 0.6]);

    $service = new EmbeddingService($provider, $cache, 3600);
    $result = $service->embedBatch(['hello']);

    expect($result)->toBe([[0.5, 0.6]]);
});
