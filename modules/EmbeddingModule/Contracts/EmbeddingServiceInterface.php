<?php

declare(strict_types=1);

namespace Modules\EmbeddingModule\Contracts;

interface EmbeddingServiceInterface
{
    public function embed(string $text): array;

    public function embedBatch(array $texts): array;
}
