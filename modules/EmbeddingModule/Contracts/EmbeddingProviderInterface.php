<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Contracts;

interface EmbeddingProviderInterface
{
    public function embed(string $text, ?string $model = null): array;

    public function embedBatch(array $texts, ?string $model = null): array;

    public function getDimensions(): int;

    public function getModelName(): string;
}
