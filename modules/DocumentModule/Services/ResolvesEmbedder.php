<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Models\Document;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\SettingsModule\Models\AiModel;

trait ResolvesEmbedder
{
    private function resolveEmbedder(
        Document $document,
        EmbeddingServiceInterface $defaultEmbedder,
        ProviderFactory $providerFactory,
    ): EmbeddingServiceInterface {
        $modelId = $document->embedding_model_id;

        if ($modelId !== null) {
            $aiModel = AiModel::find($modelId);

            if ($aiModel !== null && $aiModel->is_active) {
                return $providerFactory->createEmbeddingService($aiModel);
            }
        }

        // No (usable) per-document override: prefer the active embedding model
        // from the ai_models registry — the documented source of truth — before
        // falling back to the config-built default embedder.
        $activeModel = AiModel::active()->embedding()->orderBy('sort_order')->first();

        if ($activeModel !== null) {
            return $providerFactory->createEmbeddingService($activeModel);
        }

        return $defaultEmbedder;
    }
}
