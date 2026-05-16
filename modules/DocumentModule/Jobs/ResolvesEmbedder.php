<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Jobs;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\DocumentModule\Models\Document;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\EmbeddingService;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\SettingsModule\Models\AiModel;

trait ResolvesEmbedder
{
    private function resolveEmbedder(
        Document $document,
        EmbeddingServiceInterface $defaultEmbedder,
        ProviderFactory $providerFactory,
        CacheRepository $cache,
    ): EmbeddingServiceInterface {
        $modelId = $document->embedding_model_id;

        if ($modelId === null) {
            return $defaultEmbedder;
        }

        $aiModel = AiModel::find($modelId);

        if ($aiModel === null || ! $aiModel->is_active) {
            return $defaultEmbedder;
        }

        $provider = $providerFactory->createEmbeddingProvider($aiModel);
        $cacheTtl = $aiModel->cache_ttl ?? (int) config('rag.embedding.cache_ttl', 86400);

        return new EmbeddingService($provider, $cache, $cacheTtl);
    }
}
