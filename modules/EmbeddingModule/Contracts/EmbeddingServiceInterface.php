<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Contracts;

interface EmbeddingServiceInterface
{
    public function embed(string $text, ?string $model = null): array;

    public function embedBatch(array $texts, ?string $model = null): array;
}
