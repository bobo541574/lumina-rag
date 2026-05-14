<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Contracts;

interface TextChunkingServiceInterface
{
    public function chunk(string $text, int $chunkSize = 1000, int $overlap = 200): array;
}
