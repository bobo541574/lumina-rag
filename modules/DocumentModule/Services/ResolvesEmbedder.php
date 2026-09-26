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

        if ($modelId === null) {
            return $defaultEmbedder;
        }

        $aiModel = AiModel::find($modelId);

        if ($aiModel === null || ! $aiModel->is_active) {
            return $defaultEmbedder;
        }

        return $providerFactory->createEmbeddingService($aiModel);
    }
}
